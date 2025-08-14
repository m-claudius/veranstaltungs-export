<?php
/**
 * SeevetalParser
 * Quelle: https://www.seevetal.de/regional/veranstaltungen/sucheplus2.html
 * Strategie:
 *  - JSON-LD (script[type="application/ld+json"] mit @type=Event) zuerst.
 *  - Fallback auf DOM (Klassen: .zeit, .beschreibung, p.rubriken, "Veranstaltungsort", "Veranstalter", .images etc.)
 *  - Listenansicht: pro Suchwort (komma-/leerzeichen-getrennt) eigene Abfrage; "weiterlesen"/Detail-Links sammeln.
 */

if (!defined('ABSPATH')) { exit; }

class SeevetalParser
{
    /** Basis + Such-URL-Template */
    public const BASE_URL = 'https://www.seevetal.de';
    public const LIST_URL_TEMPLATE = 'https://www.seevetal.de/regional/veranstaltungen/sucheplus2.html?schnellauswahl=0&suchwort=%s&beginn_datum=&ende_datum=&ort=0';

    /** Öffentliche Einstiegsmethode: liefert [ 'links'=>[], 'events'=>[] ] */
    public static function crawl(string $raw_terms): array
    {
        $terms = self::normalize_terms($raw_terms);
        $all_links = [];
        foreach ($terms as $term) {
            $list_url = self::build_list_url($term);
            $html = self::fetch($list_url);
            if (!$html) { continue; }
            $links = self::parse_list_for_detail_links($html);
            // nur unique & vollständig
            foreach ($links as $u) {
                if (preg_match('~\-([0-9]{6,}\-[0-9]{5})\.html$~', $u)) {
                    $all_links[$u] = true;
                }
            }
        }

        $events = [];
        foreach (array_keys($all_links) as $detail_url) {
            $ev = self::parse_detail_page($detail_url);
            if (!empty($ev)) {
                $events[] = $ev;
            }
        }

        return [
            'links'  => array_keys($all_links),
            'events' => $events,
        ];
    }

    /* ============================================================
     * Listen- und Detail-Seite
     * ============================================================ */

    protected static function build_list_url(string $term): string
    {
        // Jeder Suchbegriff → eigene Abfrage; NICHT "Musik+Kultur", sondern getrennte Requests
        $term = trim($term);
        return sprintf(self::LIST_URL_TEMPLATE, rawurlencode($term));
    }

    /** Sucht in der Ergebnisliste nach Detail-Links */
    protected static function parse_list_for_detail_links(string $html): array
    {
        $dom = self::dom($html);
        $xp  = new DOMXPath($dom);
        $links = [];

        // 1) bevorzugt Links, die auf Detailseiten enden (...-20200.html)
        $nodes = $xp->query('//a[@href and contains(@href,"/regional/veranstaltungen/") and contains(@href,"-20200.html")]');
        foreach (self::iter($nodes) as $a) {
            $href = $a->getAttribute('href');
            $links[] = self::abs_url($href);
        }

        // 2) Fallback: „weiterlesen“-Links (falls keine 1) gefunden wurde)
        if (empty($links)) {
            $nodes = $xp->query('//a[@href and (contains(., "weiterlesen") or contains(@class,"weiter"))]');
            foreach (self::iter($nodes) as $a) {
                $href = $a->getAttribute('href');
                if (strpos($href, '/regional/veranstaltungen/') !== false) {
                    $links[] = self::abs_url($href);
                }
            }
        }

        return array_values(array_unique($links));
    }

    /** Detailseite parsen → vereinheitlichtes Event-Array */
    public static function parse_detail_page(string $url): array
    {
        $html = self::fetch($url);
        if (!$html) { return []; }

        $data = [
            'source'         => 'seevetal',
            'source_url'     => $url,
            'external_id'    => self::external_id_from_url($url),
            'title'          => '',
            'description'    => '',
            'start'          => '',
            'end'            => '',
            'image'          => '',
            'venue'          => '',
            'venue_name'     => '',
            'venue_address'  => '',
            'venue_postcode' => '',
            'venue_city'     => '',
            'organizer'      => '',
            'category'       => '',
            'contact_phone'  => '',
            'contact_email'  => '',
            'contact_url'    => '',
        ];

        // 1) JSON-LD (Event)
        $jsonld = self::extract_jsonld_events($html);
        if (!empty($jsonld)) {
            // nimm den ersten Event-Block
            $e = $jsonld[0];

            $data['title']       = trim((string)($e['name'] ?? ''));
            $data['description'] = trim((string)($e['description'] ?? ''));

            // Zeiten: bevorzugt ISO aus JSON-LD → MySQL
            $startIso = (string)($e['startDate'] ?? '');
            $endIso   = (string)($e['endDate'] ?? '');
            if ($startIso) {
                $data['start'] = self::iso_to_mysql($startIso);
            }
            if ($endIso) {
                $data['end'] = self::iso_to_mysql($endIso);
            }

            // Bild
            if (!empty($e['image'])) {
                $data['image'] = is_array($e['image']) ? (string)reset($e['image']) : (string)$e['image'];
            }

            // Ort
            if (!empty($e['location'])) {
                $loc = $e['location'];
                if (is_array($loc)) {
                    $data['venue_name'] = isset($loc['name']) ? (string)$loc['name'] : '';
                    // address kann String ODER Objekt sein
                    if (!empty($loc['address'])) {
                        if (is_array($loc['address'])) {
                            // versuche versch. Felder
                            $addrStr = trim(implode(' ', array_filter([
                                $loc['address']['streetAddress'] ?? '',
                                $loc['address']['postalCode'] ?? '',
                                $loc['address']['addressLocality'] ?? '',
                            ])));
                            if ($addrStr !== '') {
                                [$name, $street, $plz, $city] = self::split_address($data['venue_name'] . ' ' . $addrStr);
                                $data['venue_address']  = $street;
                                $data['venue_postcode'] = $plz;
                                $data['venue_city']     = $city;
                            }
                        } else {
                            // String
                            [$name, $street, $plz, $city] = self::split_address((string)$loc['address']);
                            $data['venue_address']  = $street;
                            $data['venue_postcode'] = $plz;
                            $data['venue_city']     = $city;
                        }
                    }
                }
            }

            // Veranstalter (optional im JSON-LD unterschiedlich)
            if (!empty($e['organizer'])) {
                $org = $e['organizer'];
                if (is_array($org)) {
                    $data['organizer'] = (string)($org['name'] ?? '');
                } else {
                    $data['organizer'] = (string)$org;
                }
            }
        }

        // 2) DOM-Fallbacks / Ergänzungen
        $dom = self::dom($html);
        $xp  = new DOMXPath($dom);

        // Title (Fallback)
        if ($data['title'] === '') {
            $n = $xp->query('//div[@id="nolis_content_heading"]//h3');
            if ($n && $n->length) { $data['title'] = trim($n->item(0)->textContent); }
        }

        // Beschreibung (Fallback: langbeschreibung/kurzbeschreibung)
        if ($data['description'] === '') {
            $n = $xp->query('//div[contains(@class,"langbeschreibung")]');
            if ($n && $n->length) {
                $data['description'] = trim(self::clean_text($dom, $n->item(0)));
            } else {
                $n = $xp->query('//div[contains(@class,"beschreibung")]');
                if ($n && $n->length) {
                    $data['description'] = trim(self::clean_text($dom, $n->item(0)));
                }
            }
        }

        // Datum/zeit (Fallback: <span class="zeit">Fr., 22.08.2025, 19:30 Uhr</span>)
        if ($data['start'] === '') {
            $n = $xp->query('//span[contains(@class,"zeit")]');
            if ($n && $n->length) {
                $raw = trim($n->item(0)->textContent);
                [$s, $e] = self::normalize_de_range($raw);
                $data['start'] = $s;
                $data['end']   = $e ?: $s;
            }
        }

        // Rubrik (als Kategorien-Zeile)
        $n = $xp->query('//p[contains(@class,"rubriken")]');
        if ($n && $n->length) {
            $txt = trim(self::clean_text($dom, $n->item(0)));
            // Entferne das Wort "Rubrik" und führe die Zeilen zusammen
            $txt = preg_replace('~^\s*Rubrik\s*~i', '', $txt);
            $txt = preg_replace('~\s{2,}~', ' ', str_replace(["\r","\n"], ' ', $txt));
            $data['category'] = $txt;
        }

        // Veranstaltungsort-Block (Name, Straße, PLZ Ort)
        $venueBlock = self::extract_section_after_label($xp, 'Veranstaltungsort');
        if ($venueBlock) {
            // erste Zeile: Name (ggf. im <a>)
            $first = $venueBlock[0] ?? '';
            if ($first !== '') { $data['venue_name'] = $data['venue_name'] ?: $first; }

            // Straße
            $street = '';
            $plz = ''; $city = '';
            foreach ($venueBlock as $line) {
                if (preg_match('~^\d{5}\s+~', $line)) {
                    // PLZ und Ort
                    if (preg_match('~^(\d{5})\s+(.+)$~', $line, $m)) {
                        $plz  = $m[1];
                        $city = trim($m[2]);
                    }
                } elseif ($line !== $first) {
                    // vermutlich Straße
                    if ($street === '') { $street = $line; }
                }
            }
            $data['venue_address']  = $data['venue_address'] ?: $street;
            $data['venue_postcode'] = $data['venue_postcode'] ?: $plz;
            $data['venue_city']     = $data['venue_city'] ?: $city;
            $data['venue']          = $data['venue'] ?: $data['venue_name'];
        }

        // Veranstalter (Textblock)
        if ($data['organizer'] === '') {
            $orgBlock = self::extract_section_after_label($xp, 'Veranstalter');
            if ($orgBlock) {
                $data['organizer'] = $orgBlock[0];
            }
        }

        // Kontakte (Telefon/E-Mail/Homepage)
        $contactBlock = self::extract_section_after_label($xp, 'Kontaktdaten');
        if ($contactBlock) {
            foreach ($contactBlock as $line) {
                if (stripos($line, 'Telefon') === 0) {
                    $data['contact_phone'] = trim(preg_replace('~^Telefon:\s*~i', '', $line));
                } elseif (stripos($line, 'E-Mail') === 0) {
                    $data['contact_email'] = trim(preg_replace('~^E-Mail:\s*~i', '', $line));
                } elseif (stripos($line, 'Homepage') === 0) {
                    $data['contact_url'] = trim(preg_replace('~^Homepage:\s*~i', '', $line));
                }
            }
        }

        // Bild (1) aus Bildergalerie (a[href] um <img>)
        if ($data['image'] === '') {
            $n = $xp->query('//div[contains(@class,"images")]//a[@href and (contains(@href,"/static/medien/") or contains(@href,"/static/images/"))]');
            if ($n && $n->length) {
                $data['image'] = self::abs_url($n->item(0)->getAttribute('href'));
            }
        }
        // Bild (2) og:image
        if ($data['image'] === '') {
            $n = $xp->query('//meta[@property="og:image"][@content]');
            if ($n && $n->length) {
                $data['image'] = self::abs_url($n->item(0)->getAttribute('content'));
            }
        }

        // Fallback venue zusammensetzen
        if ($data['venue'] === '') {
            $data['venue'] = trim(implode(' ', array_filter([$data['venue_name'], $data['venue_address'], $data['venue_postcode'].' '.$data['venue_city']])));
        }

        // Letzte Bereinigungen
        if ($data['end'] === '' && $data['start'] !== '') {
            $data['end'] = $data['start'];
        }

        return $data;
    }

    /* ============================================================
     * Hilfsfunktionen
     * ============================================================ */

    protected static function normalize_terms(string $raw): array
    {
        // Akzeptiert Komma und/oder Leerzeichen getrennte Wörter
        $raw = trim($raw);
        if ($raw === '') return [];
        // ersetze Komma durch Space, splitte auf Whitespace
        $norm = preg_split('~[,\s]+~u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        // doppelte raus
        return array_values(array_unique(array_map('trim', $norm)));
    }

    protected static function external_id_from_url(string $url): string
    {
        if (preg_match('~-(\d{6,}-\d{5})\.html$~', $url, $m)) {
            return 'seevetal:' . $m[1];
        }
        return 'seevetal:' . md5($url);
    }

    /** HTTP-GET robust mit User-Agent & Redirect */
    protected static function fetch(string $url): string
    {
        $args = [
            'timeout' => 20,
            'redirection' => 5,
            'user-agent' => 'Mozilla/5.0 (compatible; KSE-EventCrawler/2.0; +https://kulturstiftung-seevetal.example)',
            'sslverify' => true,
        ];
        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get($url, $args);
            if (!is_wp_error($res) && isset($res['body'])) {
                return (string)$res['body'];
            }
        }
        // Fallback: stream
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: {$args['user-agent']}\r\n",
                'timeout' => 20,
            ]
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body ?: '';
    }

    /** DOM laden */
    protected static function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        return $dom;
    }

    protected static function iter(?DOMNodeList $nl): iterable
    {
        if (!$nl) return [];
        for ($i=0; $i<$nl->length; $i++) yield $nl->item($i);
    }

    protected static function abs_url(string $href): string
    {
        if (strpos($href, '//') === 0) {
            return 'https:' . $href;
        }
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        return rtrim(self::BASE_URL, '/') . '/' . ltrim($href, '/');
    }

    /** JSON-LD Events extrahieren */
    protected static function extract_jsonld_events(string $html): array
    {
        $events = [];
        // schnelle Suche: alle <script type="application/ld+json">
        if (preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m)) {
            foreach ($m[1] as $blob) {
                $blob = self::safe_json($blob);
                if (!$blob) continue;

                $payloads = is_array($blob) && isset($blob['@context']) ? [$blob] : (is_array($blob) ? $blob : []);
                foreach ($payloads as $payload) {
                    // @graph Unterstützung
                    if (isset($payload['@graph']) && is_array($payload['@graph'])) {
                        foreach ($payload['@graph'] as $g) {
                            if (isset($g['@type']) && self::is_event_type($g['@type'])) {
                                $events[] = $g;
                            }
                        }
                    } else {
                        if (isset($payload['@type']) && self::is_event_type($payload['@type'])) {
                            $events[] = $payload;
                        }
                    }
                }
            }
        }
        return $events;
    }

    protected static function safe_json(string $json)
    {
        $json = trim($json);
        // gelegentliche HTML-Entities entschärfen
        $json = html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $data = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE) return $data;
        // Versuch: JSON-LD mit trailing Kommas etc.
        $json = preg_replace('~,\s*([\]}])~', '$1', $json);
        $data = json_decode($json, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
    }

    protected static function is_event_type($type): bool
    {
        if (is_array($type)) {
            foreach ($type as $t) {
                if (strtolower($t) === 'event') return true;
            }
            return false;
        }
        return strtolower((string)$type) === 'event';
    }

    /** ISO-8601 → MySQL */
    protected static function iso_to_mysql(string $iso): string
    {
        try {
            $dt = new DateTime($iso);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return '';
        }
    }

    /** „Fr., 22.08.2025, 19:30 Uhr“ oder „… - …“ → [start,end] (MySQL) */
    protected static function normalize_de_range(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return ['', ''];

        $to_mysql = function (string $s): string {
            $s = preg_replace('~(?:Mo|Di|Mi|Do|Fr|Sa|So)\.,?\s*~u', '', $s);
            $s = str_replace('Uhr', '', $s);
            $s = trim(str_replace(',', '', $s));
            if (preg_match('~(\d{2})\.(\d{2})\.(\d{4})\s+(\d{1,2}):(\d{2})~', $s, $m)) {
                return sprintf('%04d-%02d-%02d %02d:%02d:00', $m[3], $m[2], $m[1], $m[4], $m[5]);
            }
            if (preg_match('~(\d{2})\.(\d{2})\.(\d{4})~', $s, $m)) {
                return sprintf('%04d-%02d-%02d 00:00:00', $m[3], $m[2], $m[1]);
            }
            return '';
        };

        if (strpos($raw, ' - ') !== false) {
            [$lhs, $rhs] = array_map('trim', explode(' - ', $raw, 2));
            return [$to_mysql($lhs), $to_mysql($rhs)];
        }
        $start = $to_mysql($raw);
        return [$start, ''];
    }

    /** Text nach „<span class="h_abschnitt">Label</span>“ einsammeln (Zeilen) */
    protected static function extract_section_after_label(DOMXPath $xp, string $label): array
    {
        $out = [];
        $nodes = $xp->query('//span[@class="h_abschnitt" and normalize-space(text())="'. $label .'"]');
        if (!$nodes || !$nodes->length) return $out;

        // Parent enthält nachfolgende <br/>-getrennte Zeilen
        $parent = $nodes->item(0)->parentNode;
        if (!$parent) return $out;

        // Textknoten inkl. <a>-Texte in Reihenfolge sammeln
        $buf = [];
        foreach ($parent->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'span') {
                // das Label selbst überspringen
                continue;
            }
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'a') {
                $buf[] = trim($child->textContent);
            } elseif ($child->nodeType === XML_TEXT_NODE) {
                $buf[] = $child->wholeText;
            } elseif ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'br') {
                $buf[] = "\n";
            }
        }
        $txt = trim(preg_replace('~\n{2,}~', "\n", preg_replace('~[ \t]+~', ' ', implode('', $buf))));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $txt))));
        return $lines;
    }

    /** einfache Address-Heuristik → [name, street, plz, city] */
    protected static function split_address(string $txt): array
    {
        $txt = trim(str_replace(["\r","\n"], ' ', $txt));
        $txt = preg_replace('~\s{2,}~', ' ', $txt);

        // PLZ/Ort
        $name = ''; $street=''; $plz=''; $city='';
        if (preg_match('~\b(\d{5})\s+([A-Za-zÄÖÜäöüß\-\.\' ]{2,})\b~u', $txt, $m)) {
            $plz  = $m[1];
            $city = trim($m[2]);
            $before = trim(substr($txt, 0, strpos($txt, $m[0])));
        } else {
            $before = $txt;
        }

        // Straße+Nr
        if (preg_match('~([A-Za-zÄÖÜäöüß\-\.\' ]+)\s+\d+[a-zA-Z\-]?~u', $before, $m2)) {
            $street = trim($m2[0]);
            $name   = trim(str_replace($street, '', $before));
        } else {
            $name = $before;
        }

        return [trim($name, ', '), trim($street), $plz, $city];
    }

    protected static function clean_text(DOMDocument $dom, DOMNode $node): string
    {
        $html = $dom->saveHTML($node);
        // <br> -> \n
        $html = preg_replace('~<\s*br\s*/?>~i', "\n", $html);
        $txt = trim(strip_tags($html));
        $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = preg_replace('~[ \t]+~', ' ', $txt);
        $txt = preg_replace('~\n{2,}~', "\n", $txt);
        return trim($txt);
    }
}
