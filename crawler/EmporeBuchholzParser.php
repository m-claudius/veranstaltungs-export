<?php
if (!defined('ABSPATH')) exit;

/**
 * Empore Buchholz – Parser (angepasst für die empore-buchholz.de-Struktur)
 *
 * - Test-Limit: 10 Einträge (per crawl(['limit'=>…]) änderbar)
 * - Datum/Uhrzeit: Text NACH <span uk-icon="calendar"> / <span uk-icon="clock"> einsammeln
 *   (aus allen folgenden Geschwisterknoten; Fallback-RegEx direkt auf HTML)
 * - Beschreibung: zuerst gezielt das <div class="uk-panel uk-margin-remove-vertical" uk-scrollspy-class> mit <p>…</p>
 *   Falls das nicht greift: bestes „Chunk“ zwischen zwei Vorkommen von ' uk-scrollspy-class>' anhand der meisten <p>-Tags.
 * - Bilder: Kandidaten aus <picture>/<img>/og:image; große (width/height >= 1000) bevorzugt, dann lokal speichern;
 *   wenn Breite > 1000 → auf 1000 px verkleinern (kein Upscaling).
 *
 * Ergebnis je Event: title, description (HTML), location, image, source_url, start (Y-m-d H:i:s), end, datetime
 */
class EmporeBuchholzParser {

    const LIST_URL       = 'https://empore-buchholz.de/karten-termine/';
    const HTTP_TIMEOUT   = 20;
    const MAX_CANDIDATES = 200;

    /** Einstieg */
    public static function crawl($args = []) {
        $self    = new self();
        $listUrl = isset($args['list_url']) && is_string($args['list_url']) && $args['list_url'] ? $args['list_url'] : self::LIST_URL;
        $limit = isset($args['limit']) ? intval($args['limit']) : 0;
        $unlimited = ($limit <= 0);
        if (!$unlimited) { $limit = max(1, $limit); }

        $links = $self->collect_detail_links($listUrl);
        if (!$unlimited && count($links) > $limit) {
            $links = array_slice($links, 0, $limit);
        }

        $events = [];
        foreach ($links as $u) {
            foreach ($self->parse_detail($u) as $ev) {
                if (!empty($ev['title']) || !empty($ev['start'])) {
                    $events[] = $ev;
                }
            }
            if (!$unlimited && count($events) >= $limit) break;
        }

        return $unlimited ? $events : array_slice($events, 0, $limit);

    }


    /** ========== LISTENSEITE: Links finden ========== */
    public function collect_detail_links($url) {
        $html = $this->fetch($url);
        if ($html === '') return [];

        $dom = $this->dom($html, $url);
        $xp  = new \DOMXPath($dom);
        $set = [];

        // Primär: <a class="uk-link-toggle" ... href="...">
        $nodes = $xp->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' uk-link-toggle ')][@href]");
        foreach ($nodes ?: [] as $a) {
            /** @var \DOMElement $a */
            $href = trim($a->getAttribute('href'));
            if (!$href || $href === '#') continue;
            $abs = $this->abs_url($href, $url);
            if ($abs && preg_match('~^https?://[^/]*empore-buchholz\.de/~i', $abs)) {
                $set[$abs] = true;
                if (count($set) >= self::MAX_CANDIDATES) break;
            }
        }

        // Fallback: Grid-Karten
        if (count($set) < 5) {
            $nodes = $xp->query("//a[contains(@class,'fs-grid-item')][@href]");
            foreach ($nodes ?: [] as $a) {
                $href = trim($a->getAttribute('href'));
                if (!$href || $href === '#') continue;
                $abs = $this->abs_url($href, $url);
                if ($abs && preg_match('~^https?://[^/]*empore-buchholz\.de/~i', $abs)) {
                    $set[$abs] = true;
                    if (count($set) >= self::MAX_CANDIDATES) break;
                }
            }
        }

        // Sortiere /veranstaltung/ nach vorn
        $out = array_keys($set);
        usort($out, function($a,$b){
            $aw = strpos($a, '/veranstaltung/') !== false ? 0 : 1;
            $bw = strpos($b, '/veranstaltung/') !== false ? 0 : 1;
            return $aw <=> $bw;
        });
        return $out;
    }

    /** ========== DETAILSEITE ========== */
    public function parse_detail($url) {
        $html = $this->fetch($url);
        if ($html === '') return [];

        $dom = $this->dom($html, $url);
        $xp  = new \DOMXPath($dom);

        $title    = $this->first_text($xp, "//h1") ?: $this->first_text($xp, "//h2");
        $location = $this->detect_location($xp, $html);

        // Bild: Kandidat suchen -> lokal speichern -> ggf. auf 1000 px Breite verkleinern
        $remoteImg = $this->pick_big_image($xp, $url, $html);
        $image     = $this->save_and_resize_to_1000($remoteImg);

        // Beschreibung (gezielt zwischen h2 und booking-button; sonst bestes Chunk zwischen zwei Markern)
        $description = $this->description_panel_or_bestchunk($xp, $html);
        if (!$description) $description = $this->fallback_description($xp);

        // Datum/Uhrzeit (Icon-basiert; robustere Erfassung der Geschwistertexte; RegEx-Fallback)
        list($dateISO, $timeISO) = $this->datetime_from_icons($xp, $html);
        $start       = $this->combine_date_time($dateISO, $timeISO);
        $datetimeStr = $start ? date_i18n('Y-m-d H:i', strtotime($start)) : '';

        return [[
            'title'       => $title,
            'description' => $description, // Quelle-Zeile fügt euer Importer an
            'location'    => $location,
            'image'       => $image,
            'source_url'  => $url,
            'start'       => $start,
            'end'         => null,
            'datetime'    => $datetimeStr,
        ]];
    }

    /** Ort: JSON-LD -> DOM */
    private function detect_location(\DOMXPath $xp, $html) {
        $jsonEv = $this->parse_jsonld_min($html);
        if ($jsonEv && !empty($jsonEv['location'])) return $jsonEv['location'];
        $loc = $this->first_text($xp, "//*[contains(@class,'event-location') or contains(@class,'location') or self::address]");
        return $loc ?: '';
    }

    /* ==================== BESCHREIBUNG ==================== */

    /** bevorzugt: Panel zwischen <h2 … uk-scrollspy-class> … und <div class="booking-button … uk-scrollspy-class> */
    private function description_panel_or_bestchunk(\DOMXPath $xp, $html) {
        // 1) sehr gezielter Treffer auf die bekannte Struktur
        $nodes = $xp->query("//div[contains(@class,'uk-panel') and contains(@class,'uk-margin-remove-vertical') and @uk-scrollspy-class]");
        if ($nodes && $nodes->length) {
            /** @var \DOMElement $div */
            $div = $nodes->item(0);
            $inner = $this->dom_inner_html($div);
            // Teilen der Social-Widget-Divs entfernen
            $inner = preg_replace('~<div[^>]+class=["\']addtoany_shortcode["\'][^>]*>.*?</div>~si', '', $inner);
            $inner = trim($inner);
            if ($inner !== '') return $this->clean_desc($inner);
        }

        // 2) Bestes Chunk zwischen zwei ' uk-scrollspy-class>'-Markern (mit meisten <p>-Tags)
        $chunk = $this->best_chunk_between_scrollspy($html);
        if ($chunk) return $this->clean_desc($chunk);

        return '';
    }

    private function best_chunk_between_scrollspy($html) {
        $needle = ' uk-scrollspy-class>';
        $pos = 0; $hits = [];
        while (true) {
            $p = strpos($html, $needle, $pos);
            if ($p === false) break;
            $hits[] = $p + strlen($needle);
            $pos = $p + strlen($needle);
        }
        if (count($hits) < 2) return '';

        $best = ''; $bestScore = -1;
        for ($i=0; $i<count($hits)-1; $i++) {
            $chunk = substr($html, $hits[$i], $hits[$i+1]-$hits[$i]);
            $score = substr_count(strtolower($chunk), '<p>');
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $chunk;
            }
        }
        return $best ?: '';
    }

    private function clean_desc($html) {
        // Skripte/Styles raus
        $html = preg_replace('~<script\b[^>]*>.*?</script>~si', '', $html);
        $html = preg_replace('~<style\b[^>]*>.*?</style>~si',  '', $html);
        // Trim & redundante Whitespaces
        $html = trim(preg_replace('~\s+~', ' ', $html));
        // Minimal erlaubte Tags beibehalten
        $allowed = '<p><br><strong><em><ul><ol><li><a><b><i>';
        $html = strip_tags($html, $allowed);
        // In Container wickeln
        return '<div>' . $html . '</div>';
    }

    private function fallback_description(\DOMXPath $xp) {
        $cands = [
            "//article//p",
            "//div[contains(@class,'uk-article')]//p",
            "//div[contains(@class,'content')]//p",
            "//div[contains(@class,'uk-container')]//p",
        ];
        $parts = [];
        foreach ($cands as $q) {
            $nodes = $xp->query($q);
            foreach ($nodes ?: [] as $p) {
                $txt = trim($p->textContent ?? '');
                if ($txt !== '') $parts[] = $txt;
                if (count($parts) >= 2) break;
            }
            if (count($parts) >= 2) break;
        }
        if (!$parts) return '';
        return '<p>' . esc_html(implode("\n\n", array_unique($parts))) . '</p>';
    }

    /* ==================== DATUM/UHRZEIT über UIkit-Icons ==================== */

    /**
     * Sammelt den Text NACH dem Icon-Span aus allen nachfolgenden Geschwistern desselben Elternelements.
     * Fällt zurück auf RegEx direkt im HTML (z.B. …</span> 20:00 Uhr</div>).
     */
    private function datetime_from_icons(\DOMXPath $xp, $html) {
        $dateText = $this->icon_following_text_dom($xp, 'calendar');
        $timeText = $this->icon_following_text_dom($xp, 'clock');

        if ($dateText === '') {
            if (preg_match('~<span[^>]+uk-icon=["\']calendar["\'][^>]*>\s*</span>\s*([^<]+)~i', $html, $m)) {
                $dateText = trim($m[1]);
            }
        }
        if ($timeText === '') {
            if (preg_match('~<span[^>]+uk-icon=["\']clock["\'][^>]*>\s*</span>\s*([^<]+)~i', $html, $m)) {
                $timeText = trim($m[1]);
            }
        }

        $dateISO = $this->norm_date_de($dateText);
        $timeISO = $this->norm_time_hi($timeText);
        return [$dateISO, $timeISO];
    }

    /**
     * Text nach dem Icon im selben Elternelement einsammeln (alle nachfolgenden Geschwister).
     */
    private function icon_following_text_dom(\DOMXPath $xp, $icon) {
        $q = sprintf("//span[@uk-icon='%s' or contains(@uk-icon,'%s')]", $icon, $icon);
        $ns = $xp->query($q);
        if (!$ns || !$ns->length) return '';

        /** @var \DOMElement $span */
        $span = $ns->item(0);
        $parent = $span->parentNode;
        if (!$parent) return '';

        $buf = '';
        $collect = false;
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child->isSameNode($span)) { $collect = true; continue; }
            if (!$collect) continue;
            $buf .= ' ' . trim($child->textContent ?? '');
        }

        // Falls im Elter keiner – nimm das nächste Geschwister des Elternelements
        if (trim($buf) === '' && $parent->nextSibling) {
            $buf = trim($parent->nextSibling->textContent ?? '');
        }

        $buf = preg_replace('~\s+~', ' ', trim($buf));
        // Eventuell Icon-Wort entfernen
        $buf = preg_replace('~\b' . preg_quote($icon, '~') . '\b~i', ' ', $buf);
        return trim($buf);
    }

    /** Datum inkl. deutscher Monatsnamen (z.B. „Samstag, 06. Sep. 2025“) */
    private function norm_date_de($s) {
        $s = trim((string)$s);
        if ($s === '') return null;

        // 06.09.2025
        if (preg_match('~(\d{1,2})\.(\d{1,2})\.(\d{2,4})~', $s, $m)) {
            $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
            if ($y < 100) $y += 2000;
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        // 06. Sep. 2025 / 06 Sep 2025 / 6 Sept 25 usw. – mit/ohne Punkt
        if (preg_match('~(\d{1,2})\.?\s*([A-Za-zäöüÄÖÜ]+)\.?\,?\s+(\d{2,4})~u', $s, $m)) {
            $d = (int)$m[1];
            $mo = $this->de_month_to_num($m[2]);
            $y = (int)$m[3]; if ($y < 100) $y += 2000;
            if ($mo) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        // Fallback
        $ts = strtotime($s);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function de_month_to_num($name) {
        $t = mb_strtolower(trim((string)$name), 'UTF-8');
        $t = rtrim($t, '.');
        $map = [
            'januar'=>1, 'jan'=>1,
            'februar'=>2, 'feb'=>2,
            'märz'=>3, 'maerz'=>3, 'mrz'=>3, 'mar'=>3,
            'april'=>4, 'apr'=>4,
            'mai'=>5,
            'juni'=>6, 'jun'=>6,
            'juli'=>7, 'jul'=>7,
            'august'=>8, 'aug'=>8,
            'september'=>9, 'sept'=>9, 'sep'=>9,
            'oktober'=>10, 'okt'=>10, 'oct'=>10,
            'november'=>11, 'nov'=>11,
            'dezember'=>12, 'dez'=>12, 'dec'=>12,
        ];
        return $map[$t] ?? 0;
    }

    private function norm_time_hi($s) {
        $s = trim((string)$s);
        if ($s === '') return null;
        if (preg_match('~(\d{1,2}):(\d{2})~', $s, $m)) {
            $H = max(0, min(23, (int)$m[1]));
            $i = max(0, min(59, (int)$m[2]));
            return sprintf('%02d:%02d', $H, $i);
        }
        if (preg_match('~\b(\d{1,2})\s*Uhr\b~i', $s, $m)) {
            $H = max(0, min(23, (int)$m[1]));
            return sprintf('%02d:00', $H);
        }
        return null;
    }

    private function combine_date_time($dateISO, $timeISO) {
        if ($dateISO && $timeISO) return $dateISO . ' ' . $timeISO . ':00';
        if ($dateISO) return $dateISO . ' 00:00:00';
        return null;
    }

    /* ==================== BILDER ==================== */

    private function pick_big_image(\DOMXPath $xp, $baseUrl, $html) {
        $cands = [];

        // 1) <picture>
        $pics = $xp->query("//picture");
        foreach ($pics ?: [] as $pic) {
            foreach ($pic->getElementsByTagName('source') as $s) {
                $ss = trim($s->getAttribute('srcset'));
                if ($ss) $cands = array_merge($cands, $this->srcset_candidates($ss, $baseUrl));
            }
            foreach ($pic->getElementsByTagName('img') as $im) {
                $ss = trim($im->getAttribute('srcset'));
                if ($ss) $cands = array_merge($cands, $this->srcset_candidates($ss, $baseUrl));
                $src = trim($im->getAttribute('src'));
                if ($src) $cands[] = ['url'=>$this->abs_url($src, $baseUrl), 'w'=>$this->int_or_null($im->getAttribute('width')), 'h'=>$this->int_or_null($im->getAttribute('height'))];
            }
        }

        // 2) Allgemeine <img>
        $imgs = $xp->query("//img[@src]");
        foreach ($imgs ?: [] as $im) {
            $src = trim($im->getAttribute('src'));
            if (!$src) continue;
            $cands[] = [
                'url' => $this->abs_url($src, $baseUrl),
                'w'   => $this->int_or_null($im->getAttribute('width')),
                'h'   => $this->int_or_null($im->getAttribute('height')),
            ];
            $ss = trim($im->getAttribute('srcset'));
            if ($ss) $cands = array_merge($cands, $this->srcset_candidates($ss, $baseUrl));
        }

        // 3) og:image (+ width/height falls vorhanden)
        if (preg_match('~<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) {
            $og = ['url'=>$this->abs_url($m[1], $baseUrl), 'w'=>null, 'h'=>null];
            if (preg_match('~<meta[^>]+property=["\']og:image:width["\'][^>]+content=["\'](\d+)["\']~i',  $html, $mw)) $og['w']=intval($mw[1]);
            if (preg_match('~<meta[^>]+property=["\']og:image:height["\'][^>]+content=["\'](\d+)["\']~i', $html, $mh)) $og['h']=intval($mh[1]);
            $cands[] = $og;
        }

        // Auswahl: bevorzugt w>=1000 oder h>=1000 -> größte
        $big = array_values(array_filter($cands, function($c){
            return (isset($c['w']) && $c['w'] !== null && $c['w'] >= 1000) || (isset($c['h']) && $c['h'] !== null && $c['h'] >= 1000);
        }));
        if ($big) {
            usort($big, function($a,$b){
                $aw = max((int)($a['w']??0), (int)($a['h']??0));
                $bw = max((int)($b['w']??0), (int)($b['h']??0));
                return $bw <=> $aw;
            });
            return $big[0]['url'];
        }

        // sonst größte bekannte Breite
        $withW = array_values(array_filter($cands, fn($c)=>isset($c['w']) && $c['w'] !== null));
        if ($withW) {
            usort($withW, fn($a,$b)=>($b['w']??0) <=> ($a['w']??0));
            return $withW[0]['url'];
        }

        return $cands ? $cands[0]['url'] : null;
    }

    private function srcset_candidates($srcset, $base) {
        $out = [];
        foreach (preg_split('/\s*,\s*/', $srcset) as $part) {
            if ($part === '') continue;
            if (preg_match('~^(\S+)\s+(\d+)w$~', $part, $m)) {
                $out[] = ['url'=>$this->abs_url($m[1], $base), 'w'=>intval($m[2]), 'h'=>null];
            } else {
                $bits = preg_split('/\s+/', trim($part));
                $out[] = ['url'=>$this->abs_url($bits[0], $base), 'w'=>null, 'h'=>null];
            }
        }
        return $out;
    }

    private function int_or_null($s) { $s = trim((string)$s); return ctype_digit($s) ? intval($s) : null; }

    /** Bild lokal speichern; bei Breite > 1000 auf 1000 px verkleinern (kein Upscaling) */
    private function save_and_resize_to_1000($url) {
        if (!$url) return null;
        if (!function_exists('wp_upload_dir') || !function_exists('wp_get_image_editor')) return $url;

        $resp = wp_remote_get($url, ['timeout'=>self::HTTP_TIMEOUT]);
        if (is_wp_error($resp)) return $url;
        $code = (int)wp_remote_retrieve_response_code($resp);
        if ($code >= 400) return $url;
        $body = wp_remote_retrieve_body($resp);
        if (!$body) return $url;

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) return $url;

        $ext  = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!$ext) $ext = 'jpg';
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) $ext = 'jpg';

        $hash     = md5($url);
        $origName = 'empore-'.$hash.'-orig.'.$ext;
        $resName  = 'empore-'.$hash.'-1000w.'.$ext;

        $origPath = trailingslashit($uploads['path']).$origName;
        $resPath  = trailingslashit($uploads['path']).$resName;

        if (!file_exists($origPath)) {
            $ok = @file_put_contents($origPath, $body);
            if ($ok === false) return $url;
        }

        $editor = wp_get_image_editor($origPath);
        if (is_wp_error($editor)) {
            return $url;
        }

        $size = method_exists($editor, 'get_size') ? $editor->get_size() : null;
        $w = $size['width'] ?? null;

        if ($w && $w <= 1000) {
            return trailingslashit($uploads['url']).$origName;
        }

        $editor->resize(1000, null); // Breite 1000, Höhe proportional
        $saved = $editor->save($resPath);
        if (is_wp_error($saved)) {
            return trailingslashit($uploads['url']).$origName;
        }

        return trailingslashit($uploads['url']).$resName;
    }

    /* ==================== JSON-LD-Minimum (Location) ==================== */

    private function parse_jsonld_min($html) {
        if (!preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~si', $html, $m)) return [];
        foreach ($m[1] as $payload) {
            $payload = $this->cleanup_json($payload);
            $json = json_decode($payload, true);
            if (!$json) continue;
            $items = isset($json['@graph']) ? $json['@graph'] : (isset($json['@type']) ? [$json] : (is_array($json)?$json:[]));
            foreach ((array)$items as $it) {
                if (!is_array($it)) continue;
                $types = $it['@type'] ?? null;
                $types = is_array($types) ? $types : ($types ? [$types] : []);
                $types = array_map('strtolower', $types);
                if (!in_array('event', $types, true)) continue;
                $loc = '';
                if (!empty($it['location'])) {
                    if (is_array($it['location'])) $loc = trim((string)($it['location']['name'] ?? ''));
                    else $loc = trim((string)$it['location']);
                }
                return ['location'=>$loc];
            }
        }
        return [];
    }

    /* ==================== Low-level / Utils ==================== */

    private function fetch($url) {
        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get($url, [
                'timeout' => self::HTTP_TIMEOUT,
                'headers' => ['User-Agent'=>'KSE/1.0 (+crawler)'],
            ]);
            if (is_wp_error($res)) return '';
            $code = (int)wp_remote_retrieve_response_code($res);
            if ($code >= 400) return '';
            return (string)wp_remote_retrieve_body($res);
        }
        $ctx = stream_context_create(['http'=>['timeout'=>self::HTTP_TIMEOUT, 'header'=>"User-Agent: KSE/1.0\r\n"]]);
        $html = @file_get_contents($url, false, $ctx);
        return is_string($html) ? $html : '';
    }

    private function dom($html, $base = '') {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $html = $this->ensure_utf8($html);
        @$dom->loadHTML($html);
        libxml_clear_errors();
        if ($base && $dom->documentElement) {
            $head = $dom->getElementsByTagName('head')->item(0);
            if ($head) {
                $baseEl = $dom->createElement('base');
                $baseEl->setAttribute('href', $base);
                $head->appendChild($baseEl);
            }
        }
        return $dom;
    }

    private function dom_inner_html(\DOMNode $node) {
        $doc = $node->ownerDocument;
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }
        return $html;
    }

    private function abs_url($href, $base) {
        if (!$href) return '';
        if (preg_match('~^https?://~i', $href)) return $href;
        if (strpos($href, '//') === 0) return 'https:' . $href;
        if ($base && $href[0] === '/') {
            if (preg_match('~^(https?://[^/]+)~i', $base, $m)) return rtrim($m[1], '/') . $href;
        }
        return rtrim($base, '/') . '/' . ltrim($href, '/');
    }

    private function first_text(\DOMXPath $xp, $query) {
        $n = $xp->query($query);
        if ($n && $n->length) {
            $t = trim($n->item(0)->textContent ?? '');
            if ($t !== '') return $t;
        }
        return '';
    }

    private function ensure_utf8($s) {
        if (!is_string($s)) return '';
        if (preg_match('//u', $s)) return $s;
        if (function_exists('mb_convert_encoding')) return @mb_convert_encoding($s, 'UTF-8', 'auto');
        return $s;
    }

    private function cleanup_json($s) {
        $s = preg_replace('~<!--.*?-->~s', '', $s);
        $s = str_replace('&nbsp;', ' ', $s);
        return $s;
    }
}
