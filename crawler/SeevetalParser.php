<?php
<<<<<<< Updated upstream
class SeevetalParser {
    public function fetch($term) {
        $results = [];

        // Vollständige Such-URL
        $baseUrl = 'https://www.seevetal.de/regional/veranstaltungen/sucheplus2.html';
        $query   = http_build_query([
            'schnellauswahl' => 0,
            'suchwort'       => $term,
            'beginn_datum'   => '',
            'ende_datum'     => '',
            'ort'            => 0,
        ]);
        $html = @file_get_contents("$baseUrl?$query");
        if (!$html) {
            return $results;
        }

        // Alle Detail-Links per Regex extrahieren
        if (preg_match_all('#href="(/regional/veranstaltungen/detail-[^"]+)"#', $html, $m)) {
            $hrefs = array_unique($m[1]);
        } else {
            $hrefs = [];
        }

        foreach ($hrefs as $href) {
            $url  = 'https://www.seevetal.de' . $href;
            $data = $this->parse_detail($url);
            if (!empty($data['title'])) {
                $results[] = array_merge(['url' => $url], $data);
            }
        }

        return $results;
    }

    private function parse_detail($url) {
        $html = @file_get_contents($url);
        if (!$html) return [];

        $dom   = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        return [
            'title'       => $this->get_text($xpath, "//h1"),
            'description' => $this->get_text($xpath, "//div[contains(@class,'text')]"),
            'date'        => $this->get_text($xpath, "//*[contains(text(),'Uhr')]"),
            'location'    => $this->get_text($xpath, "//*[contains(text(),'Veranstaltungsort')]/following-sibling::*"),
        ];
    }

    private function get_text($xpath, $query) {
        $n = $xpath->query($query);
        return ($n && $n->length) ? trim($n->item(0)->textContent) : '';
=======
/**
 * SeevetalParser – sammelt Detail-Links für einen Suchbegriff
 * und parst die Detailseiten (Titel, Datum/Zeit, Beschreibung, Rubrik, Ort, Bild).
 *
 * Öffentliche API (menükompatibel):
 *  - collect_detail_links(string $term): array
 *  - get_cached_detail(string $url): array
 *
 * Rückgabe von get_cached_detail():
 *  [
 *    'title'      => string,
 *    'datetime'   => string (YYYY-mm-dd HH:ii:ss, wenn ermittelbar; sonst leer),
 *    'desc'       => string (Beschreibung, Text),
 *    'location'   => string (kompakter Orts-/Adressblock),
 *    'image'      => string (absolute URL oder leer),
 *  ]
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('SeevetalParser')) {

class SeevetalParser
{
    public const BASE       = 'https://www.seevetal.de';
    public const SEARCH_TPL = 'https://www.seevetal.de/regional/veranstaltungen/sucheplus2.html?schnellauswahl=0&suchwort=%s&beginn_datum=&ende_datum=&ort=0';
    private const UA        = 'KSE-EventCrawler/2.0 (+WordPress)';
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /* ===================== PUBLIC API ===================== */

    /**
     * Sammelt Detail-Links für einen einzelnen Suchbegriff.
     * Achtung: KEINE Mehrfachbegriffe! (pro Begriff aufrufen)
     */
    public function collect_detail_links(string $term): array
    {
        $term = trim($term);
        if ($term === '') return [];

        $url  = sprintf(self::SEARCH_TPL, rawurlencode($term));
        $html = $this->http_get($url);
        if ($html === '') return [];

        [$doc, $xp] = $this->dom($html);

        // Alle Links, die nach Detailseiten aussehen:
        // /regional/veranstaltungen/<slug>-<id>-20200.html
        $out = [];
        foreach ($xp->query("//a[@href]") as $a) {
            /** @var DOMElement $a */
            $href = trim($a->getAttribute('href'));
            if ($href === '') continue;
            if (preg_match('#/regional/veranstaltungen/[^"\']+-\d{6,}-\d+\.html#i', $href)) {
                $out[] = $this->abs($href);
                continue;
            }
            // Fallback: „weiterlesen“-Links ohne kompletten Pfad
            $txt = trim(preg_replace('/\s+/u',' ', $a->textContent ?? ''));
            if ($txt !== '' && stripos($txt, 'weiterlesen') !== false) {
                $out[] = $this->abs($href);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Liefert geparste Details mit einfachem Transient-Cache.
     */
    public function get_cached_detail(string $url): array
    {
        $key = 'kse_sev_'.md5($url);
        $hit = get_transient($key);
        if (is_array($hit)) return $hit;

        $html = $this->http_get($url);
        $res  = $html ? $this->parse_detail($html, $url) : [];
        set_transient($key, $res, self::CACHE_TTL);
        return $res;
    }

    /* ===================== INTERNALS ===================== */

    private function http_get(string $url): string
    {
        $args = [
            'timeout'     => 25,
            'redirection' => 5,
            'headers'     => [
                'User-Agent'      => self::UA,
                'Accept'          => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
            ],
        ];
        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)) return '';
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) return '';
        $body = wp_remote_retrieve_body($res);
        return is_string($body) ? $body : '';
    }

    /** @return array{0:DOMDocument,1:DOMXPath} */
    private function dom(string $html): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // Meta-Tag zur korrekten Encoding-Erkennung voranstellen:
        $doc->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />'.$html, LIBXML_NOERROR|LIBXML_NOWARNING);
        libxml_clear_errors();
        return [$doc, new DOMXPath($doc)];
    }

    private function abs(string $href): string
    {
        $href = trim($href);
        if ($href === '') return '';
        if (strpos($href, '//') === 0) return 'https:'.$href;
        if (preg_match('~^https?://~i', $href)) return $href;
        return rtrim(self::BASE,'/').'/'.ltrim($href, '/');
    }

    private function textContent(?DOMNode $n): string
    {
        $t = $n ? ($n->textContent ?? '') : '';
        $t = preg_replace('/\s+/u',' ', (string)$t);
        return trim($t ?? '');
    }

    /**
     * Extrahiert die Texte eines Blocks, der mit <span class="h_abschnitt">X</span> beginnt,
     * indem alle Kindelemente außer dem Überschrifts-Span zusammengefasst werden.
     */
    private function extractBlockByHeading(DOMXPath $xp, string $heading): string
    {
        $nodeList = $xp->query("//span[@class='h_abschnitt' and normalize-space()='{$heading}']/parent::*[1]");
        if (!$nodeList || !$nodeList->length) return '';
        /** @var DOMElement $container */
        $container = $nodeList->item(0);

        $out = [];
        foreach ($container->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'span' && $child->getAttribute('class') === 'h_abschnitt') {
                continue; // Überschrift überspringen
            }
            $txt = $this->textContent($child);
            if ($txt !== '') $out[] = $txt;
        }
        $joined = trim(preg_replace('/\s*,\s*/u', ', ', implode(' ', $out)));
        // Zeilenumbrüche aus <br> werden im DOM zu Textknoten – oben schon geglättet.
        // Noch etwas säubern:
        $joined = preg_replace('/\s{2,}/u',' ', $joined);
        return trim($joined);
    }

    private function parse_detail(string $html, string $url): array
    {
        [$doc, $xp] = $this->dom($html);

        // Titel (h3 im Inhaltsbereich)
        $title = '';
        foreach ([
            "//div[@id='nolis_content_heading']//h3[normalize-space()][1]",
            "//h3[normalize-space()][1]"
        ] as $q) {
            $title = $this->nodeText($xp, $q);
            if ($title !== '') break;
        }

        // Datum/Zeit (span.zeit)
        $dateText = $this->nodeText($xp, "//span[contains(@class,'zeit')][1]");
        $datetime = $this->parseDateTime($dateText); // YYYY-mm-dd HH:ii:ss oder leer

        // Beschreibung (langbeschreibung / beschreibung)
        $desc = '';
        foreach ([
            "//div[contains(@class,'langbeschreibung')][1]",
            "//div[contains(@class,'beschreibung')][1]"
        ] as $q) {
            $n = $xp->query($q);
            if ($n && $n->length) {
                $desc = $this->textContent($n->item(0));
                if ($desc !== '') break;
            }
        }

        // Rubriken (optional)
        $rub = '';
        $rubNode = $xp->query("//p[@class='rubriken'][1]");
        if ($rubNode && $rubNode->length) {
            $rub = $this->textContent($rubNode->item(0));
            // "Rubrik" Wort entfernen
            $rub = trim(preg_replace('/^\s*Rubrik\s*/u','', $rub));
        }

        // Veranstaltungsort
        $location = $this->extractBlockByHeading($xp, 'Veranstaltungsort');

        // Bild: erst <div class="images"> ankern -> href, dann og:image, dann erstes <img>
        $image = '';
        $a = $xp->query("//div[contains(@class,'images')]//a[@href][1]");
        if ($a && $a->length) {
            $image = $this->abs($a->item(0)->getAttribute('href'));
        }
        if ($image === '') {
            $og = $this->nodeText($xp, "//meta[@property='og:image' or @name='og:image']/@content");
            if ($og) $image = $this->abs($og);
        }
        if ($image === '') {
            $img = $xp->query("(//img[@src])[1]");
            if ($img && $img->length) $image = $this->abs($img->item(0)->getAttribute('src'));
        }

        return [
            'title'    => $title,
            'datetime' => $datetime,
            'desc'     => $desc,
            'location' => $location ?: $rub, // notfalls Rubriken anzeigen
            'image'    => $image,
        ];
    }

    private function nodeText(DOMXPath $xp, string $q): string
    {
        $n = $xp->query($q);
        if (!$n || !$n->length) return '';
        $node = $n->item(0);
        // Attribut?
        if ($node instanceof DOMAttr) {
            return trim((string)$node->value);
        }
        return $this->textContent($node);
    }

    /**
     * Wandelt „Fr., 22.08.2025, 19:30 Uhr“ und ähnliche Muster in "YYYY-mm-dd HH:ii:ss" um.
     * Unterstützt auch Bereiche wie „Fr., 29.08.2025, 16:00 Uhr - So., 31.08.2025, 19:00 Uhr“ (nimmt Startzeit).
     */
    private function parseDateTime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';

        // Trenner vereinheitlichen
        $raw = str_replace(['Uhr','–','—','|'], ['','','-','-'], $raw);
        $raw = preg_replace('/\s+/u', ' ', $raw);

        // Suche das ERSTE Datum + Zeit
        if (preg_match('/(\d{2}\.\d{2}\.\d{4})(?:,\s*(\d{1,2}:\d{2}))?/u', $raw, $m)) {
            $d = $m[1];
            $t = $m[2] ?? '00:00';
            $dt = DateTime::createFromFormat('d.m.Y H:i', $d.' '.$t);
            if ($dt) return $dt->format('Y-m-d H:i:s');
        }
        return '';
>>>>>>> Stashed changes
    }
}

}
