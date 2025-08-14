<?php
if (!defined('ABSPATH')) { exit; }

class MusikInAltenHeidekirchenParser
{
    public const BASE  = 'https://musik-in-alten-heidekirchen.wir-e.de';
    public const LIST  = 'https://musik-in-alten-heidekirchen.wir-e.de/termine';

    /**
     * Öffentlicher Einstieg. $terms werden bewusst ignoriert.
     * Liefert: ['list_urls'=>[...], 'links'=>[detail-urls], 'events'=>[...]]
     */
    public static function crawl(string $terms = ''): array
    {
        $list_html = self::fetch(self::LIST);
        if (!$list_html) {
            return ['list_urls' => [self::LIST], 'links' => [], 'events' => []];
        }

        $detail_links = self::parse_list_for_detail_links($list_html);
        $events = [];
        foreach ($detail_links as $u) {
            $ev = self::parse_detail($u);
            if (!empty($ev)) { $events[] = $ev; }
        }

        return [
            'list_urls' => [self::LIST],
            'links'     => $detail_links,
            'events'    => $events,
        ];
    }

    /** Detailseite parsen */
    public static function parse_detail(string $url): array
    {
        $html = self::fetch($url);
        if (!$html) return [];

        $dom = self::dom($html);
        $xp  = new DOMXPath($dom);

        // Titel – h1 oder og:title
        $title = '';
        $n = $xp->query('//h1');
        if ($n && $n->length) { $title = trim($n->item(0)->textContent); }
        if ($title === '') {
            $m = $xp->query('//meta[@property="og:title"][@content]');
            if ($m && $m->length) $title = trim($m->item(0)->getAttribute('content'));
        }

        // Beschreibung – Hauptinhalt/Artikeltext
        $desc = '';
        foreach ([
            '//article',
            '//*[@id="content"]',
            '//*[@id="main"]',
            '//*[contains(@class,"content")]',
        ] as $q) {
            $nn = $xp->query($q);
            if ($nn && $nn->length) { $desc = self::block_text($dom, $nn->item(0)); break; }
        }
        if ($desc === '') {
            // Fallback: alles
            $body = $xp->query('//body');
            if ($body && $body->length) $desc = self::block_text($dom, $body->item(0));
        }

        // Startzeit: time[datetime] oder „Sa., 18.10.2025, 20:00 Uhr“ im Content
        $start = '';
        $t = $xp->query('//time[@datetime]');
        if ($t && $t->length) {
            $start = self::iso_to_mysql($t->item(0)->getAttribute('datetime'));
        }
        if ($start === '') {
            // Suche deutsche Datums-/Zeitmuster
            $text = $desc;
            if (preg_match('~(\d{2})\.(\d{2})\.(\d{4})(?:,\s*(\d{1,2}):(\d{2}))?~u', $text, $m)) {
                $start = sprintf('%04d-%02d-%02d %02d:%02d:00',
                    (int)$m[3], (int)$m[2], (int)$m[1], isset($m[4])?(int)$m[4]:0, isset($m[5])?(int)$m[5]:0
                );
            }
        }
        $end = $start; // Ende vorerst = Start

        // Bild – bevorzugt og:image
        $image = '';
        $og = $xp->query('//meta[@property="og:image"][@content]');
        if ($og && $og->length) $image = self::abs($og->item(0)->getAttribute('content'));
        if ($image === '') {
            $img = $xp->query('//main//img[@src] | //article//img[@src] | //img[@src]');
            if ($img && $img->length) $image = self::abs($img->item(0)->getAttribute('src'));
        }

        // Ort/Adresse – einfache Heuristik aus Text
        $venue_name = '';
        $venue_addr = '';
        $venue_plz  = '';
        $venue_city = '';
        // Suche Zeilen mit PLZ
        if (preg_match('~(\d{5})\s+([A-Za-zÄÖÜäöüß \-\.]+)~u', $desc, $mm)) {
            $venue_plz  = trim($mm[1]);
            $venue_city = trim($mm[2]);
            // Straße davor?
            if (preg_match('~([^\n\r]+?\s+\d+[a-zA-Z\-]?)\s+' . preg_quote($venue_plz, '~') . '\b~u', $desc, $ms)) {
                $venue_addr = trim($ms[1]);
            }
        }

        // Externe ID – UUID aus URL
        $external_id = '';
        if (preg_match('~/termine/([0-9a-f\-]{16,})$~i', $url, $m)) {
            $external_id = 'musikheide:' . strtolower($m[1]);
        } else {
            $external_id = 'musikheide:' . md5($url);
        }

        return [
            'source'         => 'musikheide',
            'source_url'     => $url,
            'external_id'    => $external_id,
            'title'          => $title,
            'description'    => $desc,
            'start'          => $start,
            'end'            => $end,
            'image'          => $image,
            'venue'          => $venue_name ?: $venue_addr,
            'venue_name'     => $venue_name,
            'venue_address'  => $venue_addr,
            'venue_postcode' => $venue_plz,
            'venue_city'     => $venue_city,
        ];
    }

    /** Liste: „mehr“-Links einsammeln */
    protected static function parse_list_for_detail_links(string $html): array
    {
        $dom = self::dom($html);
        $xp  = new DOMXPath($dom);
        $out = [];

        // Links, die wie /termine/<uuid> aussehen
        $nodes = $xp->query('//a[@href and (contains(@href,"/termine/"))]');
        foreach (self::iter($nodes) as $a) {
            $href = $a->getAttribute('href');
            if (preg_match('~/termine/[0-9a-f\-]{16,}$~i', $href)) {
                $out[] = self::abs($href);
            }
        }
        return array_values(array_unique($out));
    }

    /* ========================= Helpers ========================= */

    protected static function fetch(string $url): string
    {
        $args = [
            'timeout' => 20,
            'redirection' => 5,
            'user-agent' => 'Mozilla/5.0 (compatible; KSE-EventCrawler/2.0)',
        ];
        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get($url, $args);
            if (!is_wp_error($res) && isset($res['body'])) return (string)$res['body'];
        }
        $ctx = stream_context_create(['http'=>['method'=>'GET','header'=>"User-Agent: {$args['user-agent']}\r\n",'timeout'=>20]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body ?: '';
    }

    protected static function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        return $dom;
    }
    protected static function iter(?DOMNodeList $nl): iterable { if(!$nl) return []; for($i=0;$i<$nl->length;$i++) yield $nl->item($i); }
    protected static function abs(string $href): string
    {
        if (preg_match('~^https?://~i', $href)) return $href;
        if (strpos($href, '//') === 0) return 'https:'.$href;
        return rtrim(self::BASE,'/').'/'.ltrim($href,'/');
    }
    protected static function iso_to_mysql(string $iso): string
    {
        try { return (new DateTime($iso))->format('Y-m-d H:i:s'); } catch(Throwable $e){ return ''; }
    }
    protected static function block_text(DOMDocument $dom, DOMNode $node): string
    {
        $html = $dom->saveHTML($node);
        $html = preg_replace('~<\s*br\s*/?>~i', "\n", $html);
        $txt  = trim(strip_tags($html));
        $txt  = html_entity_decode($txt, ENT_QUOTES|ENT_HTML5,'UTF-8');
        $txt  = preg_replace('~[ \t]+~',' ', $txt);
        $txt  = preg_replace('~\n{2,}~', "\n", $txt);
        return trim($txt);
    }
}
