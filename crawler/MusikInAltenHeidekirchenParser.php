<?php
/**
 * Parser für "Musik in alten Heidekirchen"
 * Robust: class-guard, DOM-Parsing mit Fallbacks, korrekte Titel (h2), Datumserkennung, Bild & Ort.
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('MusikInAltenHeidekirchenParser')) {

class MusikInAltenHeidekirchenParser
{
    /** Basis-URL der Seite */
    public const BASE_URL  = 'https://musik-in-alten-heidekirchen.wir-e.de';
    /** Standard-Listen-URL (Termine-Übersicht) */
    public const LIST_URL  = 'https://musik-in-alten-heidekirchen.wir-e.de/termine';
    /** Max. Anzahl standardmäßig */
    public const DEFAULT_MAX = 10;

    /**
     * Crawl der Listen-Seite und Parsen der Detailseiten.
     * @param string $list_url
     * @param int    $max
     * @return array
     */
    public function crawl($list_url = self::LIST_URL, $max = self::DEFAULT_MAX)
    {
        $list_html = $this->http_get($list_url);
        if ($list_html === '') { return []; }

        $links = $this->extract_detail_links($list_html);
        if (empty($links)) { return []; }

        $events = [];
        $max = (int)$max > 0 ? (int)$max : self::DEFAULT_MAX;

        foreach (array_slice($links, 0, $max) as $url) {
            $detail_html = $this->http_get($url);
            if ($detail_html === '') { continue; }
            $ev = $this->parse_detail($detail_html, $url);
            if (!empty($ev['title'])) {
                $events[] = $ev;
            }
        }
        return $events;
    }

    /* ------------------------------- intern -------------------------------- */

    private function http_get($url)
    {
        $resp = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'],
        ]);
        if (is_wp_error($resp)) { return ''; }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) { return ''; }
        $body = wp_remote_retrieve_body($resp);
        return is_string($body) ? $body : '';
    }

    /** @return array [DOMDocument, DOMXPath] */
    private function new_dom($html)
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        return [$doc, new DOMXPath($doc)];
    }

    private function xtext(DOMXPath $xp, $query)
    {
        $n = $xp->query($query);
        if (!$n || !$n->length) { return ''; }
        $txt = $n->item(0)->textContent;
        $txt = is_string($txt) ? $txt : '';
        return trim(preg_replace('/\s+/u', ' ', $txt));
    }

    private function extract_detail_links($html)
    {
        list($doc, $xp) = $this->new_dom($html);
        $out = [];

        // bevorzugt „mehr“-Links
        foreach ($xp->query("//a[contains(@href,'/termine/') and (contains(translate(normalize-space(.),'MEHR','mehr'),'mehr') or contains(@class,'more'))]") as $a) {
            $href = $a->getAttribute('href');
            if ($href) { $out[] = $this->abs($href); }
        }

        // Fallback: alle plausiblen Detail-Links (ohne reine Listen-URL)
        if (empty($out)) {
            foreach ($xp->query("//a[contains(@href,'/termine/')][@href != '/termine']") as $a) {
                $href = $a->getAttribute('href');
                if ($href && !preg_match('~/termine/?$~', $href)) {
                    $out[] = $this->abs($href);
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function parse_detail($html, $source_url)
    {
        list($doc, $xp) = $this->new_dom($html);

        // *** Titel aus <h2> (so wie auf den Detailseiten) – Backup: <title> ***
        $title = '';
        foreach ([
            "//main//article//h2[normalize-space()][1]",
            "//*[contains(@class,'event') or contains(@class,'content')]//h2[normalize-space()][1]",
            "//h2[normalize-space()][1]"
        ] as $q) {
            $title = $this->xtext($xp, $q);
            if ($title !== '') { break; }
        }
        if ($title === '') { $title = $this->xtext($xp, "//title"); }

        // Datum/Zeit – meist in <h4 class="date">…</h4>
        $date_text = $this->xtext($xp, "//h4[contains(@class,'date')][1]");
        list($start, $end) = $this->parse_datetime($date_text);

        // Ort
        $place = $this->xtext($xp, "//*[contains(@class,'place') or contains(@class,'ort')][1]");
        if ($place === '') {
            // Label-Variante „Ort:“ → nächstes Element/Text
            $place = $this->xtext($xp, "//*[contains(translate(normalize-space(), 'ORT:', 'ort:'), 'ort:')]/following-sibling::*[1]");
        }

        // Beschreibung (kurz)
        $desc = $this->xtext($xp, "//*[contains(@class,'event-content') or contains(@class,'content')]");

        // Bild
        $img = '';
        $imgNode = $xp->query("(//main//article//img | //figure//img | //img[contains(@class,'event')])[1]");
        if ($imgNode && $imgNode->length) {
            $img = $imgNode->item(0)->getAttribute('src');
            $img = $this->abs($img);
        }

        return [
            'title'       => $title,
            'start'       => $start,
            'end'         => $end,
            'location'    => $place,
            'description' => $desc,
            'image'       => $img,
            'source'      => $source_url,
        ];
    }

    private function parse_datetime($raw)
    {
        $raw = trim((string)$raw);
        if ($raw === '') { return ['', '']; }

        // Normieren
        $raw = str_replace(['Uhr', '–', '—', '|'], ['', '-', '-', '-'], $raw);
        $raw = preg_replace('/\s+/u', ' ', $raw);

        $date = '';
        if (preg_match('/(\d{2}\.\d{2}\.\d{4})/u', $raw, $m)) {
            $date = $m[1];
        }

        $times = [];
        if (preg_match_all('/\b(\d{1,2}:\d{2})\b/u', $raw, $mm)) {
            $times = $mm[1];
        }

        $start = $end = '';
        if ($date !== '') {
            if (isset($times[0])) {
                $dt = DateTime::createFromFormat('d.m.Y H:i', $date.' '.$times[0]);
                $start = $dt ? $dt->format('Y-m-d H:i:s') : '';
            } else {
                $dt = DateTime::createFromFormat('d.m.Y', $date);
                $start = $dt ? $dt->format('Y-m-d 00:00:00') : '';
            }
            if (isset($times[1])) {
                $et = DateTime::createFromFormat('d.m.Y H:i', $date.' '.$times[1]);
                $end = $et ? $et->format('Y-m-d H:i:s') : '';
            }
        }
        return [$start, $end];
    }

    private function abs($url)
    {
        $url = (string)$url;
        if ($url === '') { return ''; }
        if (strpos($url, '//') === 0) { return 'https:' . $url; }
        if (preg_match('~^https?://~i', $url)) { return $url; }
        if (strpos($url, '/') === 0) { return rtrim(self::BASE_URL, '/') . $url; }
        return rtrim(self::BASE_URL, '/') . '/' . ltrim($url, '/');
    }
}

} // class_exists
