<?php
/**
 * SeevetalParser V3.0 – Crawler für seevetal.de Veranstaltungen
 *
 * Basiert auf V2.1, erweitert um:
 *  - JSON-LD structured data als primäre Datenquelle (zuverlässigste)
 *  - Fallback auf DOM/XPath mit verifizierten Selektoren (Stand: März 2026)
 *  - Robustere Datums-Erkennung (Einzel- und Blocktermine)
 *  - Rubrik-Extraktion für Tag-Matching
 *
 * Öffentliche API:
 *  - collect_detail_links(string $term): array        – Stufe 1: Links sammeln
 *  - get_cached_detail(string $url): array             – Stufe 2: Detail parsen (mit Cache)
 *  - parse_detail(string $html, string $url): array    – Stufe 2: Detail parsen (ohne Cache)
 *
 * Rückgabe von get_cached_detail() / parse_detail():
 *  [
 *    'title'      => string,
 *    'datetime'   => string (YYYY-mm-dd HH:ii:ss, wenn ermittelbar; sonst leer),
 *    'end'        => string (YYYY-mm-dd HH:ii:ss, wenn ermittelbar; sonst leer),
 *    'desc'       => string (Beschreibung),
 *    'location'   => string (kompakter Orts-/Adressblock),
 *    'organizer'  => string,
 *    'rubrik'     => string (Rubriken, kommagetrennt),
 *    'image'      => string (absolute URL oder leer),
 *  ]
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('SeevetalParser')) {

class SeevetalParser
{
    public const BASE       = 'https://www.seevetal.de';
    public const SEARCH_TPL = 'https://www.seevetal.de/regional/veranstaltungen/sucheplus2.html?schnellauswahl=0&suchwort=%s&beginn_datum=&ende_datum=&ort=0';
    private const UA        = 'KSE-EventCrawler/3.0 (+WordPress)';
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /* ===================== PUBLIC API ===================== */

    /**
     * Stufe 1: Sammelt Detail-Links für einen einzelnen Suchbegriff.
     */
    public function collect_detail_links(string $term): array
    {
        $term = trim($term);
        if ($term === '') return [];

        $url  = sprintf(self::SEARCH_TPL, rawurlencode($term));
        $html = $this->http_get($url);
        if ($html === '') return [];

        [$doc, $xp] = $this->dom($html);

        $out = [];

        // Strategie A: Links die auf -NNNNNN-20200.html enden (Nolis-CMS-Muster)
        foreach ($xp->query("//a[contains(@href,'/regional/veranstaltungen/') and contains(@href,'-20200')]") as $a) {
            /** @var DOMElement $a */
            $href = trim($a->getAttribute('href'));
            if ($href === '' || strpos($href, 'sucheplus') !== false || strpos($href, 'suche.html') !== false) continue;
            // Nur echte Detailseiten, keine Suche/Neueintrag etc.
            if (preg_match('#/regional/veranstaltungen/[a-z0-9].*-\d{6,}-20200\.html#i', $href)) {
                $out[] = $this->abs($href);
            }
        }

        // Strategie B: Fallback – „weiterlesen"-Links
        if (empty($out)) {
            foreach ($xp->query("//a[@href]") as $a) {
                $txt = trim(preg_replace('/\s+/u', ' ', $a->textContent ?? ''));
                if ($txt !== '' && stripos($txt, 'weiterlesen') !== false) {
                    $href = trim($a->getAttribute('href'));
                    if ($href !== '' && strpos($href, 'sucheplus') === false) {
                        $out[] = $this->abs($href);
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Stufe 2: Liefert geparste Details mit einfachem Transient-Cache.
     */
    public function get_cached_detail(string $url): array
    {
        $key = 'kse_sev_' . md5($url);
        $hit = get_transient($key);
        if (is_array($hit)) return $hit;

        $html = $this->http_get($url);
        $res  = $html ? $this->parse_detail($html, $url) : [];
        set_transient($key, $res, self::CACHE_TTL);
        return $res;
    }

    /**
     * Stufe 2: Parst eine Detailseite (ohne Cache).
     * Strategie: JSON-LD zuerst (strukturiert + zuverlässig), XPath als Ergänzung/Fallback.
     */
    public function parse_detail(string $html, string $url = ''): array
    {
        $result = [
            'title'     => '',
            'datetime'  => '',
            'end'       => '',
            'desc'      => '',
            'location'  => '',
            'organizer' => '',
            'rubrik'    => '',
            'image'     => '',
        ];

        [$doc, $xp] = $this->dom($html);

        // ──────────────────────────────────────────────
        // 1) JSON-LD auslesen (zuverlässigste Quelle)
        // ──────────────────────────────────────────────
        $jsonLd = $this->extractJsonLd($html);

        if ($jsonLd) {
            $result['title'] = trim($jsonLd['name'] ?? '');

            // Start-/Enddatum aus ISO 8601
            if (!empty($jsonLd['startDate'])) {
                $result['datetime'] = $this->isoToLocal($jsonLd['startDate']);
            }
            if (!empty($jsonLd['endDate'])) {
                $result['end'] = $this->isoToLocal($jsonLd['endDate']);
            }

            // Ort
            $loc = $jsonLd['location'] ?? [];
            if (is_array($loc)) {
                $parts = array_filter([
                    $loc['name'] ?? '',
                    $loc['address'] ?? '',
                ]);
                $result['location'] = trim(implode(', ', $parts));
                // Adresse säubern (Zeilenumbrüche → Komma)
                $result['location'] = preg_replace('/\s*\n\s*/u', ', ', $result['location']);
            }

            // Veranstalter
            $org = $jsonLd['organizer'] ?? [];
            if (is_array($org)) {
                $result['organizer'] = trim($org['name'] ?? '');
            }

            // Bild
            if (!empty($jsonLd['image'])) {
                $result['image'] = $this->abs(is_array($jsonLd['image']) ? ($jsonLd['image'][0] ?? '') : $jsonLd['image']);
            }
        }

        // ──────────────────────────────────────────────
        // 2) XPath-Ergänzungen / Fallbacks
        //    (verifiziert gegen echtes Seevetal HTML, Stand März 2026)
        //    Struktur: <div id="nolis_content_heading" class="nolis_termine_internet_ansicht">
        // ──────────────────────────────────────────────

        // Titel Fallback: <h3> im Content-Bereich
        if ($result['title'] === '') {
            $result['title'] = $this->nodeText($xp, "//div[@id='nolis_content_heading']//h3[normalize-space()][1]");
            if ($result['title'] === '') {
                $result['title'] = $this->nodeText($xp, "//h3[normalize-space()][1]");
            }
        }

        // Datum Fallback: <span class="zeit">Fr., 29.05.2026 - So., 31.05.2026</span>
        if ($result['datetime'] === '') {
            $zeitText = $this->nodeText($xp, "//span[contains(@class,'zeit')][1]");
            if ($zeitText !== '') {
                $result['datetime'] = $this->parseDateTime($zeitText);
                // Bei Blocktermin: Enddatum parsen
                if ($result['end'] === '' && preg_match('/\d{2}\.\d{2}\.\d{4}.*[-–—]\s*\S+\s*(\d{2}\.\d{2}\.\d{4})/u', $zeitText, $m)) {
                    $result['end'] = $this->parseDateTime($m[1]);
                }
            }
        }

        // Beschreibung: <div class="langbeschreibung"> oder <div class="beschreibung">
        if ($result['desc'] === '') {
            foreach ([
                "//div[contains(@class,'langbeschreibung')][1]",
                "//div[contains(@class,'beschreibung')][1]",
            ] as $q) {
                $n = $xp->query($q);
                if ($n && $n->length) {
                    $result['desc'] = $this->textContent($n->item(0));
                    if ($result['desc'] !== '') break;
                }
            }
        }

        // Ort Fallback: Block mit <span class="h_abschnitt">Veranstaltungsort</span>
        if ($result['location'] === '') {
            $result['location'] = $this->extractBlockByHeading($xp, 'Veranstaltungsort');
        }

        // Veranstalter Fallback
        if ($result['organizer'] === '') {
            $result['organizer'] = $this->extractBlockByHeading($xp, 'Veranstalter');
        }

        // Rubrik (immer aus XPath, nicht in JSON-LD)
        $rubNode = $xp->query("//p[@class='rubriken'][1]");
        if ($rubNode && $rubNode->length) {
            $rub = $this->textContent($rubNode->item(0));
            $rub = trim(preg_replace('/^\s*Rubrik\s*/ui', '', $rub));
            $result['rubrik'] = $rub;
        }

        // Bild Fallback: <div class="images"> → <a href="...jpg"> → og:image
        if ($result['image'] === '') {
            $a = $xp->query("//div[contains(@class,'images')]//a[@href][1]");
            if ($a && $a->length) {
                $result['image'] = $this->abs($a->item(0)->getAttribute('href'));
            }
        }
        if ($result['image'] === '') {
            $og = $this->nodeText($xp, "//meta[@property='og:image']/@content");
            if ($og) $result['image'] = $this->abs($og);
        }

        return $result;
    }

    /* ===================== INTERNALS ===================== */

    /**
     * Extrahiert das erste Event-JSON-LD aus dem HTML.
     */
    private function extractJsonLd(string $html): ?array
    {
        // Alle <script type="application/ld+json"> finden
        if (!preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $json) {
            $data = json_decode(trim($json), true);
            if (!is_array($data)) continue;

            // Direkt ein Event?
            if (($data['@type'] ?? '') === 'Event') {
                return $data;
            }

            // In einem @graph?
            if (isset($data['@graph']) && is_array($data['@graph'])) {
                foreach ($data['@graph'] as $item) {
                    if (is_array($item) && ($item['@type'] ?? '') === 'Event') {
                        return $item;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Wandelt ISO 8601 in lokale WP-Zeit (Y-m-d H:i:s) um.
     */
    private function isoToLocal(string $iso): string
    {
        $iso = trim($iso);
        if ($iso === '') return '';
        try {
            $dt = new DateTime($iso);
            $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Berlin');
            $dt->setTimezone($tz);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return '';
        }
    }

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
        if (is_wp_error($res)) {
            error_log('[KSE-Seevetal] HTTP-Fehler für ' . $url . ': ' . $res->get_error_message());
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            error_log('[KSE-Seevetal] HTTP ' . $code . ' für ' . $url);
            return '';
        }
        $body = wp_remote_retrieve_body($res);
        return is_string($body) ? $body : '';
    }

    /** @return array{0:DOMDocument,1:DOMXPath} */
    private function dom(string $html): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        return [$doc, new DOMXPath($doc)];
    }

    private function abs(string $href): string
    {
        $href = trim($href);
        if ($href === '') return '';
        if (strpos($href, '//') === 0) return 'https:' . $href;
        if (preg_match('~^https?://~i', $href)) return $href;
        return rtrim(self::BASE, '/') . '/' . ltrim($href, '/');
    }

    private function textContent(?DOMNode $n): string
    {
        $t = $n ? ($n->textContent ?? '') : '';
        $t = preg_replace('/\s+/u', ' ', (string) $t);
        return trim($t ?? '');
    }

    private function nodeText(DOMXPath $xp, string $q): string
    {
        $n = $xp->query($q);
        if (!$n || !$n->length) return '';
        $node = $n->item(0);
        if ($node instanceof DOMAttr) {
            return trim((string) $node->value);
        }
        return $this->textContent($node);
    }

    /**
     * Extrahiert die Texte eines Blocks, der mit <span class="h_abschnitt">X</span> beginnt.
     * Verifiziert gegen echtes Seevetal-HTML (März 2026).
     */
    private function extractBlockByHeading(DOMXPath $xp, string $heading): string
    {
        // Der Abschnitt sitzt in einem <div> das das <span class="h_abschnitt"> enthält
        $nodeList = $xp->query("//span[@class='h_abschnitt' and normalize-space()='{$heading}']/parent::*[1]");
        if (!$nodeList || !$nodeList->length) return '';

        /** @var DOMElement $container */
        $container = $nodeList->item(0);

        $out = [];
        foreach ($container->childNodes as $child) {
            // Überschrift-Span überspringen
            if ($child instanceof DOMElement && $child->tagName === 'span' && $child->getAttribute('class') === 'h_abschnitt') {
                continue;
            }
            // <br> als Trenner behandeln
            if ($child instanceof DOMElement && $child->tagName === 'br') {
                continue;
            }
            $txt = $this->textContent($child);
            if ($txt !== '') $out[] = $txt;
        }

        $joined = trim(implode(', ', $out));
        // Doppelte Kommas/Leerzeichen säubern
        $joined = preg_replace('/,\s*,/u', ',', $joined);
        $joined = preg_replace('/\s{2,}/u', ' ', $joined);
        return trim($joined, ", \t\n\r");
    }

    /**
     * Wandelt „Fr., 22.08.2025, 19:30 Uhr" und ähnliche Muster in "YYYY-mm-dd HH:ii:ss" um.
     * Unterstützt auch: „Fr., 29.05.2026 - So., 31.05.2026" (nimmt Startzeit).
     */
    private function parseDateTime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';

        // &nbsp; und Uhr entfernen, Trenner vereinheitlichen
        $raw = str_replace(["\xC2\xA0", '&nbsp;', 'Uhr', '–', '—', '|'], [' ', ' ', '', '-', '-', '-'], $raw);
        $raw = preg_replace('/\s+/u', ' ', $raw);

        // Suche das ERSTE Datum + optionale Zeit
        if (preg_match('/(\d{2}\.\d{2}\.\d{4})(?:[,\s]*(\d{1,2}:\d{2}))?/u', $raw, $m)) {
            $d = $m[1];
            $t = $m[2] ?? '00:00';
            $dt = DateTime::createFromFormat('d.m.Y H:i', $d . ' ' . $t);
            if ($dt) return $dt->format('Y-m-d H:i:s');
        }
        return '';
    }
}

} // end class_exists
