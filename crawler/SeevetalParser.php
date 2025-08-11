<?php
/**
 * SeevetalParser
 * - Sucht Detail-Links auf der Seevetal-Suche (sucheplus2.html)
 * - Fallback: Portal-Suche /portal/suche.html
 * - Parst Detailseiten: DOM-Heuristiken + JSON-LD (Event) als verlässlicher Fallback
 * - Bietet Admin-Hilfsfunktionen (Debug, Render)
 */
if (!defined('ABSPATH')) exit;

class SeevetalParser {

    private $last_http = null;
    public function get_last_http_meta(): array {
        return is_array($this->last_http) ? $this->last_http : [];
    }

    private function normalize_terms($input): array {
        if (is_array($input)) {
            $joined = implode(' ', array_map('strval', $input));
        } else {
            $joined = (string)$input;
        }
        // Split an Komma oder beliebiges Whitespace
        $parts = preg_split('/[,\s]+/u', $joined, -1, PREG_SPLIT_NO_EMPTY);
        $parts = array_values(array_unique(array_map('trim', $parts)));
        return $parts;
    }


    /* =========================================================
     * Admin: Debug – Detail-Links pro Suchbegriff einsammeln
     * ========================================================= */
public function debug_collect_detail_links(string $term): array {
        $tokens = $this->normalize_terms($term);
        if (empty($tokens)) {
            return ['url' => '', 'urls' => [], 'bytes' => 0, 'links' => []];
        }

        $all_links = [];
        $bytes_sum = 0;
        $urls_map  = [];

        foreach ($tokens as $tok) {
            // Primär: spezial-Suche
            $base = 'https://www.seevetal.de/regional/veranstaltungen/sucheplus2.html';
            $url  = $base . '?' . http_build_query([
                'schnellauswahl' => 0,
                'suchwort'       => $tok,
                'beginn_datum'   => '',
                'ende_datum'     => '',
                'ort'            => 0,
            ]);
            error_log('DEBUG: Abruf Suchseite ' . $url);

            $html  = $this->http_get($url, 'https://www.seevetal.de/regional/veranstaltungen/sucheplus2.html');
            $links = $this->extract_detail_links($html);
            $bytes = strlen((string)$html);
            $bytes_sum += $bytes;
            $urls_map[$tok] = $url;

            if (empty($links)) {
                // Fallback: Portal-Suche
                $fallback = 'https://www.seevetal.de/portal/suche.html?suchbegriff=' . rawurlencode($tok);
                error_log('DEBUG: Fallback Portal ' . $fallback);

                $html2  = $this->http_get($fallback, 'https://www.seevetal.de/portal/suche.html');
                $links2 = $this->extract_detail_links($html2);
                $bytes_sum += strlen((string)$html2);

                if (!empty($links2)) {
                    $links = $links2;
                    $urls_map[$tok] = $fallback;
                }
            }

            foreach ($links as $L) $all_links[] = $L;
        }

        $all_links = array_values(array_unique($all_links));

        return [
            // 'url' bleibt leer, wenn mehrere Tokens – stattdessen 'urls' je Token
            'url'   => (count($tokens) === 1) ? ($urls_map[$tokens[0]] ?? '') : '',
            'urls'  => $urls_map,
            'bytes' => $bytes_sum,
            'links' => $all_links,
        ];
    }


    /* ==========================================
     * Öffentliche Sammelfunktion (ohne Debug)
     * ========================================== */
    public function collect_detail_links(string $term): array {
        $dbg = $this->debug_collect_detail_links($term);
        return $dbg['links'];
    }

    /* ===================================================
     * Links aus Suchseite extrahieren (XPath + Regex)
     * =================================================== */
    private function extract_detail_links(string $html): array {
        $results = [];
        if (!$html) return $results;

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xp = new \DOMXPath($dom);

        // A) generische a/@href unterhalb /regional/veranstaltungen/ … .html
        foreach ($xp->query("//a[contains(@href,'/regional/veranstaltungen/') and contains(@href,'.html')]/@href") as $attr) {
            $href = trim((string) $attr->value);
            if ($href) $results[] = $this->absolute_url($href);
        }

        // B) sichtbarer Text „weiterlesen“
        if (empty($results)) {
            foreach ($xp->query("//a[contains(translate(normalize-space(string(.)),'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ','abcdefghijklmnopqrstuvwxyzäöü'),'weiterlesen')]/@href") as $attr) {
                $href = trim((string) $attr->value);
                if ($href) $results[] = $this->absolute_url($href);
            }
        }

        // C) Regex-Fallback (sehr breit, letzte Rettung)
        if (empty($results)) {
            if (preg_match_all('#/regional/veranstaltungen/[^\s"\']+?\.html#u', $html, $m)) {
                foreach ($m[0] as $p) {
                    $results[] = $this->absolute_url($p);
                }
            }
        }

        // Heuristik: nur „echte“ Detailseiten-URLs
        $results = array_values(array_filter(array_unique($results), function ($u) {
            return (bool) preg_match('#^https?://www\.seevetal\.de/regional/veranstaltungen/.+\.html$#i', $u);
        }));

        return $results;
    }

    /* ==========================================
     * Detailseite parsen (DOM + JSON-LD Fallback)
     * ========================================== */
    public function parse_detail_page(string $url): array {
        $html = $this->http_get($url, 'https://www.seevetal.de/');
        if (!$html) return [];

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp  = new \DOMXPath($dom);

        // JSON-LD ziehe ich zuerst (ist meist vollständig)
        $json = $this->extract_jsonld_event($xp);
        $domF = $this->extract_dom_fields($xp);

        $data = [
            'title'        => $domF['title']        ?: ($json['name']        ?? ''),
            'datetime'     => $domF['datetime']     ?: ($json['startDate']   ?? ''),
            'location'     => $domF['location']     ?: ($json['location']    ?? ''),
            'description'  => $domF['description']  ?: ($json['description'] ?? ''),
            'costs'        => $domF['costs']        ?: '',
            'age'          => $domF['age']          ?: '',
            'registration' => $domF['registration'] ?: '',
            'organizer'    => $domF['organizer']    ?: ($json['organizer']   ?? ''),
            'image'        => $domF['image']        ?: ($json['image']       ?? ''),
            'url'          => $url,
        ];

        // einfache Tag-Ableitung (kann später durch Mapping ersetzt werden)
        $data['tags'] = $this->derive_tags(
            ($data['title'] ?? '') . ' ' . ($data['description'] ?? ''),
            ['Kultur','Musik','Bildung','Führung','Kunst','Literatur','Schauspiel','Lesungen','Konzert']
        );

        return array_filter($data, static function($v) {
            return !(is_string($v) && trim($v) === '');
        });
    }

    /* ==========================================
     * Admin-Ausgabe (Kurzliste mit Details)
     * ========================================== */
    public function render_admin(array $terms): string {
        $out = '';
        foreach ($terms as $term) {
            $links = $this->collect_detail_links($term);
            $out .= '<h2>' . esc_html($term) . ' — Links: ' . count($links) . '</h2>';
            if (!$links) {
                $out .= '<p><em>Keine Veranstaltungen gefunden.</em></p>';
                continue;
            }
            foreach ($links as $L) {
                $e = $this->get_cached_detail($L);
                $out .= '<div class="ve-event" style="padding:10px;border:1px solid #ddd;margin:10px 0;">';
                $out .= '<h3 style="margin-top:0">' . esc_html($e['title'] ?? '(ohne Titel)') . '</h3>';
                if (!empty($e['image'])) {
                    $out .= '<img class="ve-event-img" src="' . esc_url($e['image']) . '" alt="" style="max-width:280px;height:auto;display:block;margin:6px 0">';
                }
                if (!empty($e['datetime']))    $out .= '<p><strong>Datum/Zeit:</strong> ' . esc_html($e['datetime']) . '</p>';
                if (!empty($e['location']))    $out .= '<p><strong>Ort:</strong> ' . esc_html($e['location']) . '</p>';
                if (!empty($e['description'])) $out .= '<p>' . nl2br(esc_html($e['description'])) . '</p>';
                if (!empty($e['tags']))        $out .= '<p><strong>Tags:</strong> ' . esc_html(implode(', ', (array)$e['tags'])) . '</p>';

                $admin = wp_nonce_url(
                    add_query_arg([
                        'page'      => 've_export',
                        've_action' => 'details',
                        'url'       => rawurlencode($L),
                    ], admin_url('admin.php')),
                    've_details'
                );
                $out .= '<p><a href="' . esc_url($L) . '" target="_blank" rel="noopener">Extern</a> &nbsp;|&nbsp; <a href="' . esc_url($admin) . '">Details (Admin)</a></p>';
                $out .= '</div>';
            }
        }
        return $out;
    }

    /* ==========================================
     * Detail-Caching (Transient)
     * ========================================== */
    public function get_cached_detail(string $url): array {
        $key    = 've_evt_' . md5($url);
        $cached = get_transient($key);
        if (is_array($cached)) return $cached;

        $data = $this->parse_detail_page($url);
        if (!empty($data)) set_transient($key, $data, HOUR_IN_SECONDS);
        return $data;
    }

    /* ==========================================
     * JSON-LD Event extrahieren
     * ========================================== */
    private function extract_jsonld_event(\DOMXPath $xp): array {
        foreach ($xp->query("//script[@type='application/ld+json']") as $script) {
            $txt = trim($script->textContent ?? '');
            if ($txt === '') continue;

            $json = json_decode($txt, true);
            if (!$json) continue;

            $candidates = [];
            if (isset($json['@type'])) $candidates[] = $json;
            if (isset($json['@graph']) && is_array($json['@graph'])) {
                $candidates = array_merge($candidates, $json['@graph']);
            }

            foreach ($candidates as $obj) {
                if (!is_array($obj)) continue;
                if (($obj['@type'] ?? '') !== 'Event') continue;

                // location zusammenbauen
                $loc = '';
                if (!empty($obj['location'])) {
                    if (isset($obj['location']['name']) || isset($obj['location']['address'])) {
                        $parts = [];
                        if (!empty($obj['location']['name']))    $parts[] = $obj['location']['name'];
                        if (!empty($obj['location']['address'])) $parts[] = is_array($obj['location']['address']) ? implode(' ', $obj['location']['address']) : $obj['location']['address'];
                        $loc = $this->normalize_ws(implode(' — ', $parts));
                    } elseif (is_string($obj['location'])) {
                        $loc = $obj['location'];
                    }
                }

                // image kann String oder Array sein
                $image = '';
                if (!empty($obj['image'])) {
                    $image = is_array($obj['image']) ? (string) reset($obj['image']) : (string) $obj['image'];
                }

                // organizer kann Objekt oder String sein
                $org = '';
                if (!empty($obj['organizer'])) {
                    $org = is_array($obj['organizer']) ? ($obj['organizer']['name'] ?? '') : (string) $obj['organizer'];
                }

                return array_filter([
                    'name'        => $obj['name']        ?? '',
                    'startDate'   => $obj['startDate']   ?? '',
                    'description' => $this->normalize_ws($obj['description'] ?? ''),
                    'location'    => $loc,
                    'organizer'   => $org,
                    'image'       => $image,
                ], static function($v) {
                    return !(is_string($v) && trim($v) === '');
                });
            }
        }
        return [];
    }

    /* ==========================================
     * DOM-Heuristiken (ergänzen JSON-LD)
     * ========================================== */
    private function extract_dom_fields(\DOMXPath $xp): array {
        $title = $this->normalize_ws($xp->evaluate('string(//h1[1])'));

        // Bild: zuerst im Content, sonst og:image
        $image = '';
        foreach ($xp->query("(//article|//main|//div[contains(@class,'content')]|//body)//img[@src][1]") as $img) {
            $image = trim($img->getAttribute('src')); break;
        }
        if (!$image) {
            $og = trim($xp->evaluate("string(//meta[@property='og:image']/@content)"));
            if ($og) $image = $og;
        }

        // Datum/Zeit: nahe H1 oder erster Block danach, ansonsten Muster
        $datetime = $this->normalize_ws($xp->evaluate('string((//h1[1]/following::*[self::p or self::div][1]))'));
        if (!$datetime) {
            $pageText = $this->normalize_ws($xp->evaluate('string(//body)'));
            if (preg_match('/\b(Mo\.|Di\.|Mi\.|Do\.|Fr\.|Sa\.|So\.)[^\n]+?\d{2}:\d{2}[^\n]*/u', $pageText, $m)) {
                $datetime = trim($m[0]);
            }
        }

        $location     = $this->extract_section($xp, ['Ort','Veranstaltungsort']);
        $costs        = $this->extract_section($xp, ['Kosten','Eintritt']);
        $age          = $this->extract_section($xp, ['Alter']);
        $registration = $this->extract_section($xp, ['Anmeldung','Anmeldung und Bezahlung']);
        $organizer    = $this->extract_section($xp, ['Veranstalter']);

        // Beschreibung: zwischen H1 und erster Sektion
        $description = '';
        $firstSecNode = $xp->query("//*[self::h2 or self::h3 or self::strong][normalize-space(.)=('Wann') or normalize-space(.)=('Ort') or normalize-space(.)=('Information') or normalize-space(.)=('Kosten') or normalize-space(.)=('Alter') or normalize-space(.)=('Anmeldung') or normalize-space(.)=('Veranstalter')][1]")->item(0);
        if ($firstSecNode) {
            $start = $xp->query('//h1[1]/following::*[self::p or self::div][1]')->item(0);
            if ($start) {
                $buf = '';
                for ($n = $start; $n && $n !== $firstSecNode; $n = $n->nextSibling) {
                    if ($n->nodeType === XML_ELEMENT_NODE) {
                        $tag = strtolower($n->nodeName);
                        if (in_array($tag, ['h2','h3','strong'], true)) break;
                        $buf .= ' ' . $this->normalize_ws($xp->evaluate('string(.)', $n));
                    }
                }
                $description = $this->normalize_ws($buf);
            }
        }
        if (!$description) {
            $description = $this->normalize_ws($xp->evaluate('string(((//h1[1]/following::p)[1]))'));
        }

        return compact('title','image','datetime','location','costs','age','registration','organizer','description');
    }

    private function extract_section(\DOMXPath $xp, array $labels): string {
        $pred = [];
        foreach ($labels as $L) $pred[] = "normalize-space(.)='{$L}'";
        $hdr = $xp->query("//*[self::h2 or self::h3 or self::strong][" . implode(' or ', $pred) . "][1]")->item(0);
        if (!$hdr) return '';
        $out = '';
        for ($n = $hdr->nextSibling; $n; $n = $n->nextSibling) {
            if ($n->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($n->nodeName);
                if (in_array($tag, ['h2','h3','strong'], true)) break;
                $txt = $this->normalize_ws($xp->evaluate('string(.)', $n));
                if ($txt !== '') $out .= ' ' . $txt;
            }
        }
        return $this->normalize_ws($out);
    }

    private function derive_tags(string $text, array $pool): array {
        $t = mb_strtolower($text, 'UTF-8');
        $out = [];
        foreach ($pool as $p) {
            if (mb_strpos($t, mb_strtolower($p, 'UTF-8')) !== false) $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    /* ==========================================
     * HTTP & Utils
     * ========================================== */
    private function http_get(string $url, string $referer = ''): string {
        $args = [
            'timeout'     => 20,
            'redirection' => 10,
            'headers'     => [
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
                'Cache-Control'   => 'no-cache',
            ],
        ];
        if ($referer) $args['headers']['Referer'] = $referer;

        $resp = wp_remote_get($url, $args);
        if (is_wp_error($resp)) {
            error_log('VE http_get WP_Error: ' . $resp->get_error_message());
            return '';
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);
        $this->last_http = [
            'url'   => $url,
            'code'  => (int) $code,
            'bytes' => strlen($body),
        ];

        error_log(sprintf('VE http_get %s => %d (%d bytes)', $url, $code, strlen($body)));

        if ($code !== 200) return '';
        return $body;
    }

    private function absolute_url(string $href): string {
        $href = html_entity_decode(trim($href));
        if ($href === '') return '';
        if (strpos($href, 'http') === 0) return $href;
        return 'https://www.seevetal.de' . (strpos($href, '/') === 0 ? $href : '/' . $href);
    }

    private function normalize_ws(string $s): string {
        $s = preg_replace('/\s+/u', ' ', $s ?? '');
        return trim((string) $s);
    }
}
