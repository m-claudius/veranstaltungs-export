<?php
/**
 * SeevetalParser – Crawler/Parser für https://www.seevetal.de/regional/veranstaltungen/
 * Drop-in Datei für: crawler/SeevetalParser.php
 */
class SeevetalParser
{
    const BASE = 'https://www.seevetal.de';

    /** @var callable|null */
    protected $logger;

    public function __construct(callable $logger = null)
    {
        // Logger: function (string $msg) { error_log($msg); }
        $this->logger = $logger;
    }

    /* =============================
     * Öffentliche API
     * ============================= */

    /**
     * Holt für EINEN Suchbegriff die Detail-Links und parst optional alle Events.
     *
     * @param string $term           z.B. "Musik"
     * @param int    $maxEvents      0 = alle, sonst Limit
     * @param bool   $parseDetails   true = Detailseiten mit auslesen
     * @return array {term, search_url, detail_links[], events[]}
     */
    public function fetch_events_for_term(string $term, int $maxEvents = 0, bool $parseDetails = true): array
    {
        $term = trim($term);
        $searchUrl = $this->build_search_url($term);
        $this->log("DEBUG: Abruf Suchseite $searchUrl");

        $html = $this->http_get($searchUrl, self::BASE . '/regional/veranstaltungen/');
        if (!$html) {
            return [
                'term'         => $term,
                'search_url'   => $searchUrl,
                'detail_links' => [],
                'events'       => [],
                'error'        => 'HTTP fetch failed for search page',
            ];
        }

        $links = $this->parse_listing_for_detail_links($html, $searchUrl);
        $this->log('DEBUG: Gefundene Detail-Links: ' . count($links) . " für $term");

        $events = [];
        if ($parseDetails) {
            $count = 0;
            foreach ($links as $u) {
                $events[] = $this->parse_detail_page($u);
                $count++;
                if ($maxEvents > 0 && $count >= $maxEvents) {
                    break;
                }
            }
        }

        return [
            'term'         => $term,
            'search_url'   => $searchUrl,
            'detail_links' => $links,
            'events'       => $events,
        ];
    }

    /**
     * Mehrere Suchbegriffe (getrennt gepflegt; NICHT „Musik Kultur“).
     * @param string[] $terms
     * @param int $maxPerTerm
     * @return array
     */
    public function crawl_terms(array $terms, int $maxPerTerm = 0): array
    {
        $out = [];
        foreach ($terms as $t) {
            $t = trim($t);
            if ($t === '') continue;
            $out[] = $this->fetch_events_for_term($t, $maxPerTerm, true);
        }
        return $out;
    }

    /* =============================
     * Listing – Links finden
     * ============================= */

    protected function build_search_url(string $term): string
    {
        // ein Begriff pro Request, exakt so wie das Frontend
        $q = http_build_query([
            'schnellauswahl' => '0',
            'suchwort'       => $term,
            'beginn_datum'   => '',
            'ende_datum'     => '',
            'ort'            => '0',
        ]);
        return self::BASE . '/regional/veranstaltungen/sucheplus2.html?' . $q;
    }

    /**
     * Extrahiert alle Detail-Links aus einer Ergebnisliste.
     * Erkennt:
     *  - „weiterlesen“-Links
     *  - alle hrefs, die wie Veranstaltungs-Detailseiten aussehen
     *  - wandelt zu absoluten URLs
     */
    protected function parse_listing_for_detail_links(string $html, string $baseUrl): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xp = new \DOMXPath($dom);

        $links = [];

        // 1) „weiterlesen“
        foreach ($xp->query("//a[contains(normalize-space(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ', 'abcdefghijklmnopqrstuvwxyzäöü')), 'weiterlesen')]") as $a) {
            /** @var \DOMElement $a */
            $href = trim($a->getAttribute('href'));
            if ($href) $links[] = $this->abs_url($href, $baseUrl);
        }

        // 2) alle „/regional/veranstaltungen/*.html“-Links
        foreach ($xp->query("//a[contains(@href, '/regional/veranstaltungen/') and contains(@href,'.html')]") as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href) $links[] = $this->abs_url($href, $baseUrl);
        }

        // dedupe + nur Details (keine Liste, keine Anker)
        $links = array_values(array_unique(array_filter($links, function ($u) {
            if (stripos($u, '/regional/veranstaltungen/') === false) return false;
            if (preg_match('#/sucheplus2\.html#i', $u)) return false;
            return (bool)preg_match('#/regional/veranstaltungen/.+\.html(\?.*)?$#i', $u);
        })));

        return $links;
    }

    /* =============================
     * Detailseite – Werte extrahieren
     * ============================= */

    /**
     * Parst eine Veranstaltungs-Detailseite robust.
     * Gibt immer ein Array mit Standardfeldern zurück (leere Strings falls nicht vorhanden).
     */
    public function parse_detail_page(string $url): array
    {
        // ---- HTML laden (WP oder PHP-Fallback) ----
        $html = '';
        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get($url, [
                'timeout'     => 25,
                'redirection' => 5,
                'user-agent'  => 'Seevetal-Exporter/2.0 (detail-parser)',
            ]);
            if (!is_wp_error($res)) $html = wp_remote_retrieve_body($res);
        }
        if (!$html) {
            $ctx  = stream_context_create(['http'=>['timeout'=>25,'header'=>"User-Agent: Seevetal-Exporter/2.0\r\n"]]);
            $html = @file_get_contents($url, false, $ctx) ?: '';
        }
        if (!$html) return ['error'=>'no_html','url'=>$url];

        // ---- DOM + XPath ----
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xp  = new \DOMXPath($dom);

        $norm = function (?string $s): string {
            if ($s === null) return '';
            $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $s = preg_replace('/\x{00A0}/u',' ',$s);
            $s = preg_replace('/[ \t\r\n]+/u',' ',$s);
            return trim($s);
        };
        $textOf = function($n) use ($norm){ return $n ? $norm($n->textContent ?? '') : ''; };
        $first = function(string $q) use ($xp){ $n=$xp->query($q); return ($n && $n->length)?$n->item(0):null; };

        // ---- Titel (h3 auf der Seite) + Datum/Uhrzeit (span.zeit) ----
        $titleNode = $first("//div[@id='nolis_content_heading']//h3 | //h1");
        $title     = $textOf($titleNode);

        $dtNode    = $first("//div[@id='nolis_content_heading']//span[contains(@class,'zeit')]");
        $datetime  = $textOf($dtNode);

        // ---- Beschreibung (div.beschreibung / .langbeschreibung) ----
        $descNode = $first("//div[contains(@class,'beschreibung')]");
        $description = '';
        if ($descNode) {
            $description = $norm($xp->evaluate("string(.//text())", $descNode));
        }

        // ---- Helfer: alle Zeilen nach <span class='h_abschnitt'>LABEL</span><br> einsammeln ----
        $collectLinesAfterLabel = function(string $label) use ($xp, $norm): array {
            $span = $xp->query("//span[contains(concat(' ', normalize-space(@class), ' '), ' h_abschnitt ')][normalize-space(.)='$label']");
            if (!$span || !$span->length) return [];
            $span = $span->item(0);

            $lines = [];
            $n = $span->nextSibling;
            $guard = 0;
            while ($n && $guard++ < 200) {
                if ($n->nodeType === XML_ELEMENT_NODE) {
                    // nächster Abschnitt beginnt wieder mit span.h_abschnitt
                    if (strtolower($n->nodeName) === 'span' &&
                        preg_match('/\bh_abschnitt\b/i', $n->getAttribute('class') ?? '')) {
                        break;
                    }
                    if (strtolower($n->nodeName) === 'br') { $n = $n->nextSibling; continue; }

                    // Linktext, P/Div/Textcontainer aufsammeln
                    if (in_array(strtolower($n->nodeName), ['a','p','div','span','address','li'], true)) {
                        $t = $xp->evaluate('string(.)', $n);
                        $t = $norm($t);
                        if ($t !== '') $lines[] = $t;
                    }
                } elseif ($n->nodeType === XML_TEXT_NODE) {
                    $t = $norm($n->nodeValue ?? '');
                    if ($t !== '') $lines[] = $t;
                }
                $n = $n->nextSibling;
                // Bei Containerwechsel (wir sind in <p> oder <div>), brechen wir ab, sobald der Parent endet.
                if ($span->parentNode && $n && $n->parentNode !== $span->parentNode) break;
            }

            // Leere raus, doppelte weg
            $lines = array_values(array_unique(array_filter(array_map('trim', $lines), fn($s)=>$s!=='')));
            return $lines;
        };

        // ---- Rubrik -> Kategorienliste ----
        $rubrikLines = $collectLinesAfterLabel('Rubrik');
        // Eine Zeile kann mehrere Kategorien mit "/" enthalten
        $categories = [];
        foreach ($rubrikLines as $line) {
            $parts = preg_split('~/~', $line);
            foreach ($parts as $p) {
                $p = trim($p, " \t\n\r\0\x0B,-");
                if ($p !== '') $categories[] = $p;
            }
        }
        $categories = array_values(array_unique($categories));

        // ---- Veranstaltungsort ----
        $venueLines = $collectLinesAfterLabel('Veranstaltungsort');
        $venue_name = $venue_street = $venue_city = '';
        if ($venueLines) {
            // 0: Name (kommt meist als <a>), 1: Straße, 2: PLZ Ort
            if (isset($venueLines[0])) $venue_name   = $venueLines[0];
            if (isset($venueLines[1])) $venue_street = $venueLines[1];
            if (isset($venueLines[2])) $venue_city   = $venueLines[2];
        }

        // ---- Veranstalter ----
        $orgLines = $collectLinesAfterLabel('Veranstalter');
        $organizer = $organizer_addr = '';
        if ($orgLines) {
            if (isset($orgLines[0])) $organizer      = $orgLines[0];
            if (isset($orgLines[1])) $organizer_addr = $orgLines[1];
        }

        // ---- Kontaktdaten ----
        $contactLines = $collectLinesAfterLabel('Kontaktdaten');
        $phone=$email=$website='';
        if ($contactLines) {
            $blob = implode("\n", $contactLines);
            if (preg_match('/Telefon:\s*([+0-9 ().\-\/]+)/iu', $blob, $m)) $phone = trim($m[1]);
            if (preg_match('/E-?Mail:\s*([^\s,;]+)/iu',        $blob, $m)) $email = trim($m[1]);
            if (!$email) { // evtl. steckt die Mail im Linktext
                foreach ($contactLines as $ln) {
                    if (filter_var($ln, FILTER_VALIDATE_EMAIL)) { $email = $ln; break; }
                }
            }
            if (preg_match('/Homepage:\s*([^\s]+)\b/iu', $blob, $m)) $website = trim($m[1]);
        }

        // ---- Bild: erst Vollbild-Link aus .images, sonst og:image/erstes <img> ----
        $image = '';
        $imgHref = $xp->evaluate("string((//div[contains(@class,'images')]//a[@href][1])/@href)");
        if ($imgHref) $image = $norm($imgHref);
        if (!$image) {
            $image = $norm($xp->evaluate("string(//meta[@property='og:image']/@content)"));
            if (!$image) $image = $norm($xp->evaluate("string((//article//img|//main//img|//img)[1]/@src)"));
        }
        if ($image && !preg_match('~^https?://~i',$image)) {
            $p = parse_url($url);
            $base = $p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
            $path = isset($p['path']) ? preg_replace('~/[^/]*$~','/',$p['path']) : '/';
            $image = ($image[0]=='/') ? $base.$image : $base.$path.$image;
        }

        // ---- Ergebnis ----
        return [
            'url'             => $url,
            'title'           => $title,
            'datetime'        => $datetime,
            'description'     => $description,
            'categories'      => $categories,
            'venue_name'      => $venue_name,
            'venue_street'    => $venue_street,
            'venue_city'      => $venue_city,
            'organizer'       => $organizer,
            'organizer_addr'  => $organizer_addr,
            'contact_phone'   => $phone,
            'contact_email'   => $email,
            'contact_website' => $website,
            'image'           => $image,
        ];
    }





    /* =============================
     * HTTP & Utils
     * ============================= */

    protected function http_get(string $url, string $referer = ''): ?string
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

        // 1) WordPress HTTP API
        if (function_exists('wp_remote_get')) {
            $args = [
                'headers' => [
                    'User-Agent' => $ua,
                    'Referer'    => $referer ?: self::BASE . '/',
                    'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
                'timeout' => 20,
                'redirection' => 5,
            ];
            $resp = wp_remote_get($url, $args);
            if (!is_wp_error($resp)) {
                $code = wp_remote_retrieve_response_code($resp);
                if ($code >= 200 && $code < 300) {
                    return (string)wp_remote_retrieve_body($resp);
                }
                $this->log("HTTP (WP) $code für $url");
            } else {
                $this->log('HTTP (WP) Fehler: ' . $resp->get_error_message());
            }
        }

        // 2) cURL
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_HTTPHEADER     => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Referer: ' . ($referer ?: self::BASE . '/'),
                ],
            ]);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($err) {
                $this->log("HTTP (cURL) Fehler: $err");
            } elseif ($code >= 200 && $code < 300 && is_string($body)) {
                return $body;
            } else {
                $this->log("HTTP (cURL) $code für $url");
            }
        }

        // 3) Fallback
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "User-Agent: $ua\r\nAccept: text/html\r\n" .
                             'Referer: ' . ($referer ?: self::BASE . '/') . "\r\n",
                'timeout' => 20,
            ]
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false) return $body;

        return null;
    }

    protected function abs_url(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) return $href;
        if (strlen($href) && $href[0] === '/') {
            return rtrim(self::BASE, '/') . $href;
        }
        // relativ zum base
        $p = parse_url($base);
        $root = $p['scheme'] . '://' . $p['host'];
        $path = isset($p['path']) ? $p['path'] : '/';
        $path = preg_replace('#/[^/]*$#', '/', $path);
        return $root . $path . $href;
    }

    protected function log(string $msg): void
    {
        if ($this->logger) {
            call_user_func($this->logger, $msg);
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log($msg);
            }
        }
    }
}
