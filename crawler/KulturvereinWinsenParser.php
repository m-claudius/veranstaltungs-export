<?php
// Sicherheitsnetz
if (!defined('ABSPATH')) { exit; }

/**
 * Kulturverein Winsen – Crawler (NEU)
 * Übersicht: https://www.kv-winsen.de/programm/index.html
 * Detail:    https://kv-winsen.reservix.de/p/reservix/event/<id>
 */
class KulturvereinWinsenParser {

    /**
     * Crawl: holt alle Detail-Events von der Programmseite.
     * Args:
     *  - list_url (string, optional)
     *  - limit    (int, 0 oder <=0 = unlimitiert)
     */
    public static function crawl(array $args = []) : array {
        $listUrl   = isset($args['list_url']) ? (string)$args['list_url'] : 'https://www.kv-winsen.de/programm/index.html';
        $limit     = isset($args['limit']) ? (int)$args['limit'] : 0;
        $unlimited = ($limit <= 0);
        if (!$unlimited) { $limit = max(1, $limit); }

        $links = self::collect_detail_links($listUrl);
        if (!$unlimited && count($links) > $limit) {
            $links = array_slice($links, 0, $limit);
        }

        $events = [];
        foreach ($links as $u) {
            // Detailseite gezielt mit Referer laden (Reservix erwartet oft einen Referer)
            $html = self::http_get_body($u, ['referer' => $listUrl]);
            $parsed = self::parse_detail_html($u, $html);

            foreach ($parsed as $ev) {
                // Minimal-Validierung
                if (!empty($ev['title']) && (!empty($ev['start']) || !empty($ev['datetime']))) {
                    $events[] = $ev;
                }
            }
            if (!$unlimited && count($events) >= $limit) { break; }
        }

        return $unlimited ? $events : array_slice($events, 0, $limit);
    }

    /**
     * Sammelt Reservix-Detail-Links von der Programmseite.
     */
    public static function collect_detail_links(string $listUrl) : array {
        $html = self::http_get_body($listUrl);
        if ($html === '') { return []; }

        $xp = self::html_xpath($html);
        $hrefs = [];

        // 1) Bevorzugt Buttons/Links mit Text "Infos und Tickets"
        $q1 = "//a[contains(translate(normalize-space(string(.)),'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ','abcdefghijklmnopqrstuvwxyzäöü'),'infos und tickets')]";
        foreach ($xp->query($q1) as $a) {
            $href = trim((string)$a->getAttribute('href'));
            if ($href) $hrefs[] = self::abs_url($href, $listUrl);
        }

        // 2) Fallback: alles mit "reservix" im href
        foreach ($xp->query("//a[contains(@href,'reservix')]") as $a) {
            $href = trim((string)$a->getAttribute('href'));
            if ($href) $hrefs[] = self::abs_url($href, $listUrl);
        }

        // Entdoppeln & nur /event/<id>-Seiten nehmen
        $hrefs = array_values(array_unique($hrefs));
        $hrefs = array_values(array_filter($hrefs, function($u){
            return (bool) preg_match('~reservix\.de/.*/event/\d+~i', $u);
        }));
        return $hrefs;
    }

    /**
     * Parsed eine bereits geladene Detailseite (HTML → Event-Array).
     */
    private static function parse_detail_html(string $url, string $html) : array {
        if ($html === '') return [];
        $xp = self::html_xpath($html);

        /* ---------- Titel ---------- */
        $title = self::first_attr($xp, "//meta[@property='og:title']", "content");
        if ($title === '') {
            $title = self::first_text($xp, "//h1");
        }
        $title = wp_strip_all_tags($title);
        // Unicode-Striche in ASCII '-' wandeln, dann Suffix " - Reservix - dein Ticketportal" am Ende entfernen
        $title = str_replace(array("–","—"), "-", $title);
        $title = preg_replace('/\s*-\s*reservix\s*-\s*dein\s*ticketportal\s*$/i', '', (string)$title);
        $title = trim((string)$title);

        /* ---------- Bild ---------- */
        $image = self::first_attr($xp, "//meta[@property='og:image']", "content");
        if ($image === '') {
            $image = self::first_attr($xp, "//figure//img", "src");
        }

        /* ---------- Beschreibung ---------- */
        $desc_meta = self::first_attr($xp, "//meta[@property='og:description' or @name='description']", "content");
        $desc_meta = esc_html((string)$desc_meta);

        $infoHtml = '';
        $nodes = $xp->query("//div[contains(@class,'c-compact-info__event-text')]//div[contains(@class,'c-text__paragraph')]");
        if ($nodes && $nodes->length) {
            foreach ($nodes as $n) {
                $inner = self::node_inner_html($n);
                if ($inner !== '') {
                    $infoHtml .= ($infoHtml ? "<br/>\n" : "") . $inner;
                }
            }
        }

        /* ---------- Ort / Adresse (normalisiert, ohne Dubletten) ---------- */
        $venue  = self::first_text($xp, "//address[contains(@class,'c-venue-address')]//div[contains(@class,'__name')]");
        $addrEl = $xp->query("//address[contains(@class,'c-venue-address')]//*[contains(@class,'__line')]");
        $addr   = [];
        if ($addrEl && $addrEl->length) {
            foreach ($addrEl as $el) {
                $t = trim((string)$el->textContent);
                if ($t !== '') { $addr[] = $t; }
            }
        }
        $location = self::normalize_location($venue, $addr);

        /* ---------- Zeitpunkt ---------- */
        $iso = self::first_attr($xp, "//time[contains(@class,'c-event-date') or contains(@class,'event-date')]", "datetime");
        $start = '';
        if ($iso !== '') {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Berlin');
            $dt = date_create((string)$iso, $tz);
            if ($dt) { $start = $dt->format('Y-m-d H:i:s'); }
        }
        if ($start === '') {
            $pool = $desc_meta . ' ' . self::first_text($xp, "//p[contains(@class,'c-ticket-fan__date')]");
            $start = self::parse_de_datetime($pool);
        }

        /* ---------- Beschreibung zusammenbauen + Quelle ---------- */
        $descParts = [];
        if ($desc_meta !== '') $descParts[] = '<p>' . $desc_meta . '</p>';
        if ($infoHtml !== '')  $descParts[] = $infoHtml;

        $host = parse_url($url, PHP_URL_HOST);
        $link = '<p><em>Quelle: (C) <a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($host ?: $url) . '</a></em></p>';
        $descParts[] = $link;

        $description = implode("\n", $descParts);


        /* ---------- Bild optional in WP-Mediathek ziehen ---------- */
        if (function_exists('kse_maybe_import_image') && $image) {
            $image = kse_maybe_import_image($image);
        }

        return [[
            'title'       => $title,
            'start'       => $start,      // 'Y-m-d H:i:s' (Europe/Berlin)
            'location'    => $location,
            'description' => $description,
            'image'       => $image,
            'source_url'  => $url,
        ]];
    }

    /* ===================== HTTP & HTML Helpers ===================== */

    // Öffentlich, damit Admin-Debug sie verwenden kann
    public static function http_get_body(string $url, array $opts = []) : string {
        $headers = array(
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0',
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'de-DE,de;q=0.9,en-US;q=0.6,en;q=0.5',
        );
        if (!empty($opts['referer'])) {
            $headers['Referer'] = (string)$opts['referer'];
        }

        $res = wp_remote_get($url, array(
            'timeout'     => 20,
            'redirection' => 5,
            'headers'     => $headers,
            'sslverify'   => true,
        ));
        if (is_wp_error($res)) { return ''; }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) { return ''; }
        $body = wp_remote_retrieve_body($res);
        return is_string($body) ? $body : '';
    }

    private static function html_xpath(string $html) : DOMXPath {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        // loadHTML erwartet latin1; wir ignorieren Warnungen und arbeiten nur mit den relevanten Knoten
        $doc->loadHTML($html);
        libxml_clear_errors();
        return new DOMXPath($doc);
    }

    private static function first_attr(DOMXPath $xp, string $query, string $attr) : string {
        $n = $xp->query($query);
        if ($n && $n->length) {
            $v = $n->item(0)->getAttribute($attr);
            return trim((string)$v);
        }
        return '';
    }

    private static function first_text(DOMXPath $xp, string $query) : string {
        $n = $xp->query($query);
        if ($n && $n->length) {
            $t = (string) $n->item(0)->textContent;
            // Mehrfach-Whitespace reduzieren
            $t = preg_replace('/\s+/', ' ', $t);
            return trim($t);
        }
        return '';
    }

    private static function node_inner_html(DOMNode $node) : string {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return trim((string)$html);
    }

    private static function abs_url(string $href, string $base) : string {
        if (preg_match('~^https?://~i', $href)) { return $href; }
        $p = wp_parse_url($base);
        if (!$p) return $href;
        $scheme = isset($p['scheme']) ? $p['scheme'] : 'https';
        $host   = isset($p['host']) ? $p['host'] : '';
        if ($href !== '' && $href[0] === '/') {
            return $scheme . '://' . $host . $href;
        }
        $path = isset($p['path']) ? preg_replace('~/[^/]*$~', '/', $p['path']) : '/';
        return $scheme . '://' . $host . $path . $href;
    }

    /**
     * Parser für deutsche Datums-/Zeitstrings:
     * Beispiel: "Fr. 05.09.2025 um 19:00 Uhr" → "2025-09-05 19:00:00"
     */
    private static function parse_de_datetime(string $txt) : string {
        $txt = (string)$txt;
        if ($txt === '') return '';
        if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $txt, $m)) return '';
        $d = $m[1]; $M = $m[2]; $Y = $m[3];

        $H = '00'; $i = '00';
        if (preg_match('/\b(\d{1,2}):(\d{2})\b/', $txt, $t)) {
            $H = str_pad($t[1], 2, '0', STR_PAD_LEFT);
            $i = $t[2];
        }

        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Berlin');
        $dt = date_create("$Y-$M-$d $H:$i:00", $tz);
        return $dt ? $dt->format('Y-m-d H:i:s') : '';
    }

    /* ===================== Location-Normalisierung ===================== */

    private static function normalize_location(string $venue, array $addrLines) : string {
        $venueNorm = self::norm_token($venue);

        // Zeilen in Komma-Tokens aufspalten
        $tokens = array();
        foreach ($addrLines as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            // Kommas normieren: ", " als Trenner
            $line = preg_replace('/\s*,\s*/', ', ', $line);
            $parts = array_map('trim', explode(',', $line));
            foreach ($parts as $tok) {
                $tok = self::norm_token($tok);
                if ($tok !== '') $tokens[] = $tok;
            }
        }

        // PLZ + Ort zusammenziehen: "21423", "Winsen (Luhe)" -> "21423 Winsen (Luhe)"
        $tokens2 = array();
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (preg_match('/^\d{5}$/', $t) && ($i+1 < $n) && !preg_match('/^\d{5}$/', $tokens[$i+1])) {
                $tokens2[] = $t . ' ' . $tokens[$i+1];
                $i++; // nächsten überspringen
            } else {
                $tokens2[] = $t;
            }
        }

        // Case-insensitive Deduplizierung; Venue zuerst
        $seen  = array();
        $parts = array();
        if ($venueNorm !== '') {
            $parts[] = $venueNorm;
            $seen[strtolower($venueNorm)] = true;
        }
        foreach ($tokens2 as $t) {
            $key = strtolower($t);
            if ($t === '' || isset($seen[$key])) continue;
            if ($venueNorm !== '' && $key === strtolower($venueNorm)) continue;
            $seen[$key] = true;
            $parts[] = $t;
        }

        return implode(', ', $parts);
    }

    private static function norm_token(string $s) : string {
        $s = trim((string)$s);
        if ($s === '') return '';
        // Mehrfach-Whitespace -> 1 Space
        $s = preg_replace('/\s+/', ' ', $s);
        // Kommas normieren
        $s = preg_replace('/\s*,\s*/', ', ', $s);
        return $s;
    }
}
