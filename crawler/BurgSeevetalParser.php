<?php
/**
 * BurgSeevetalParser V1.0 – Crawler für burg-seevetal.de Veranstaltungen
 *
 * Crawlt alle Veranstaltungen (ohne Suchbegriff) von der Burg Seevetal Website.
 * Unterstützt Paginierung (mehrere Seiten), JSON-LD + XPath-Extraktion.
 *
 * Öffentliche API:
 *  - collect_all_detail_links(int $max_pages = 10): array   – alle Detail-URLs (über alle Seiten)
 *  - get_cached_detail(string $url): array                    – Detail parsen (mit Cache)
 *  - parse_detail(string $html, string $url): array           – Detail parsen (ohne Cache)
 *
 * Rückgabe von get_cached_detail() / parse_detail():
 *  [
 *    'title'      => string,
 *    'datetime'   => string (YYYY-mm-dd HH:ii:ss),
 *    'end'        => string (YYYY-mm-dd HH:ii:ss, bei Zeitbereichen),
 *    'desc'       => string,
 *    'location'   => string,
 *    'organizer'  => string,
 *    'image'      => string (absolute URL),
 *    'ticket_url' => string (Reservix o.ä., wenn vorhanden),
 *    'costs'      => string (Eintrittspreis, wenn vorhanden),
 *  ]
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('BurgSeevetalParser')) {

class BurgSeevetalParser
{
    public const BASE      = 'https://www.burg-seevetal.de';
    public const LIST_URL  = 'https://www.burg-seevetal.de/portal/veranstaltungen/suche.html?titel=Veranstaltungen&suche=1&termine=0&zeitauswahl=4';
    private const UA       = 'KSE-BurgCrawler/1.0 (+WordPress)';
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /* ===================== PUBLIC API ===================== */

    /**
     * Sammelt alle Detail-Links über alle paginierten Seiten.
     * Burg-Seevetal zeigt ~15 Events pro Seite mit ?p0=N Paginierung.
     */
    public function collect_all_detail_links(int $max_pages = 10): array
    {
        $all_links = [];

        for ($page = 1; $page <= $max_pages; $page++) {
            $url = self::LIST_URL . '&p0=' . $page;
            $html = $this->http_get($url);
            if ($html === '') break;

            $links = $this->extract_detail_links_from_page($html);
            if (empty($links)) break; // Keine Links mehr → letzte Seite überschritten

            $before = count($all_links);
            $all_links = array_merge($all_links, $links);
            $all_links = array_values(array_unique($all_links));

            // Wenn keine neuen Links dazukamen → fertig
            if (count($all_links) === $before) break;

            error_log("[KSE-Burg] Seite $page: " . count($links) . " Links gefunden");
        }

        error_log("[KSE-Burg] Gesamt: " . count($all_links) . " Detail-Links gesammelt");
        return $all_links;
    }

    /**
     * Liefert geparste Details mit Transient-Cache.
     */
    public function get_cached_detail(string $url): array
    {
        $key = 'kse_burg_' . md5($url);
        $hit = get_transient($key);
        if (is_array($hit)) return $hit;

        $html = $this->http_get($url);
        $res  = $html ? $this->parse_detail($html, $url) : [];
        set_transient($key, $res, self::CACHE_TTL);
        return $res;
    }

    /**
     * Parst eine Detailseite.
     * Strategie: JSON-LD zuerst, XPath als Ergänzung/Fallback.
     * Burg-Seevetal nutzt das gleiche Nolis-CMS wie Gemeinde Seevetal.
     */
    public function parse_detail(string $html, string $url = ''): array
    {
        $result = [
            'title'      => '',
            'datetime'   => '',
            'end'        => '',
            'desc'       => '',
            'location'   => 'Burg Seevetal, Am Göhlenbach 11, 21218 Seevetal', // Standardort
            'organizer'  => '',
            'image'      => '',
            'ticket_url' => '',
            'costs'      => '',
        ];

        [$doc, $xp] = $this->dom($html);

        // ──── 1) JSON-LD (zuverlässigste Quelle) ────
        $jsonLd = $this->extractJsonLd($html);

        if ($jsonLd) {
            $result['title'] = trim($jsonLd['name'] ?? '');

            if (!empty($jsonLd['startDate'])) {
                $result['datetime'] = $this->isoToLocal($jsonLd['startDate']);
            }
            if (!empty($jsonLd['endDate'])) {
                $result['end'] = $this->isoToLocal($jsonLd['endDate']);
            }

            $loc = $jsonLd['location'] ?? [];
            if (is_array($loc)) {
                $parts = array_filter([
                    $loc['name'] ?? '',
                    is_string($loc['address'] ?? null) ? $loc['address'] : '',
                ]);
                if ($parts) {
                    $result['location'] = preg_replace('/\s*\n\s*/u', ', ', trim(implode(', ', $parts)));
                }
            }

            $org = $jsonLd['organizer'] ?? [];
            if (is_array($org)) {
                $result['organizer'] = trim($org['name'] ?? '');
            }

            if (!empty($jsonLd['image'])) {
                $img = is_array($jsonLd['image']) ? ($jsonLd['image'][0] ?? '') : $jsonLd['image'];
                $result['image'] = $this->abs($img);
            }
        }

        // ──── 2) XPath-Ergänzungen / Fallbacks ────

        // Titel: <h3> im Content-Bereich
        if ($result['title'] === '') {
            $result['title'] = $this->nodeText($xp, "//div[@id='nolis_content_heading']//h3[1]");
            if ($result['title'] === '') {
                $result['title'] = $this->nodeText($xp, "//h3[normalize-space()][1]");
            }
        }

        // Datum: <span class="zeit">
        if ($result['datetime'] === '') {
            $zeitText = $this->nodeText($xp, "//span[contains(@class,'zeit')][1]");
            if ($zeitText !== '') {
                $result['datetime'] = $this->parseDateTime($zeitText);
                // Enddatum bei Zeitbereichen (z.B. "20:00 - 22:00 Uhr" oder "Fr. - So.")
                if ($result['end'] === '' && preg_match('/(\d{1,2}:\d{2})\s*[-–—]\s*(\d{1,2}:\d{2})/u', $zeitText, $m)) {
                    // Gleicher Tag, andere Endzeit
                    if (preg_match('/(\d{2}\.\d{2}\.\d{4})/u', $zeitText, $dm)) {
                        $dt = DateTime::createFromFormat('d.m.Y H:i', $dm[1] . ' ' . $m[2]);
                        if ($dt) $result['end'] = $dt->format('Y-m-d H:i:s');
                    }
                }
                // Blocktermin: zweites Datum
                if ($result['end'] === '' && preg_match('/\d{2}\.\d{2}\.\d{4}.*[-–—]\s*\S+\s*(\d{2}\.\d{2}\.\d{4})/u', $zeitText, $m)) {
                    $result['end'] = $this->parseDateTime($m[1]);
                }
            }
        }

        // Beschreibung: div.langbeschreibung → div.beschreibung
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

        // Ort Fallback: Block mit h_abschnitt "Veranstaltungsort"
        if ($result['location'] === '' || $result['location'] === 'Burg Seevetal, Am Göhlenbach 11, 21218 Seevetal') {
            $loc = $this->extractBlockByHeading($xp, 'Veranstaltungsort');
            if ($loc !== '') $result['location'] = $loc;
        }

        // Veranstalter Fallback
        if ($result['organizer'] === '') {
            $result['organizer'] = $this->extractBlockByHeading($xp, 'Veranstalter');
        }

        // Bild Fallback: div.images → og:image
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

        // Ticket-URL: Reservix-Link
        $ticketNodes = $xp->query("//a[contains(@href,'reservix.de')]/@href");
        if ($ticketNodes && $ticketNodes->length) {
            $result['ticket_url'] = trim($ticketNodes->item(0)->value);
        }

        // Kosten: Tabelle mit "Eintritt:"
        $costsNode = $xp->query("//td[normalize-space()='Eintritt:']/following-sibling::td[1]");
        if ($costsNode && $costsNode->length) {
            $result['costs'] = $this->textContent($costsNode->item(0));
        }

        return $result;
    }

    /* ===================== INTERNALS ===================== */

    /**
     * Extrahiert Detail-Links aus einer einzelnen Listenansicht-Seite.
     * Link-Muster: /portal/veranstaltungen/<slug>-<id>-10.html
     */
    private function extract_detail_links_from_page(string $html): array
    {
        [$doc, $xp] = $this->dom($html);
        $out = [];

        // Links zu Detailseiten: enthalten "/portal/veranstaltungen/" und enden auf "-10.html"
        foreach ($xp->query("//a[contains(@href,'/portal/veranstaltungen/') and contains(@href,'-10.html')]") as $a) {
            $href = trim($a->getAttribute('href'));
            if ($href === '') continue;
            // Suchseite/Übersicht ausschließen
            if (strpos($href, 'suche.html') !== false) continue;
            // Nur echte Detail-Slugs (mindestens ein Buchstabe vor der ID)
            if (preg_match('#/portal/veranstaltungen/[a-z].*-\d{3,}-10\.html#i', $href)) {
                $out[] = $this->abs($href);
            }
        }

        return array_values(array_unique($out));
    }

    private function extractJsonLd(string $html): ?array
    {
        if (!preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $html, $matches)) {
            return null;
        }
        foreach ($matches[1] as $json) {
            $data = json_decode(trim($json), true);
            if (!is_array($data)) continue;
            if (($data['@type'] ?? '') === 'Event') return $data;
            if (isset($data['@graph']) && is_array($data['@graph'])) {
                foreach ($data['@graph'] as $item) {
                    if (is_array($item) && ($item['@type'] ?? '') === 'Event') return $item;
                }
            }
        }
        return null;
    }

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
            error_log('[KSE-Burg] HTTP-Fehler: ' . $url . ' → ' . $res->get_error_message());
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            error_log('[KSE-Burg] HTTP ' . $code . ' für ' . $url);
            return '';
        }
        return wp_remote_retrieve_body($res) ?: '';
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
        return trim(preg_replace('/\s+/u', ' ', (string) $t) ?? '');
    }

    private function nodeText(DOMXPath $xp, string $q): string
    {
        $n = $xp->query($q);
        if (!$n || !$n->length) return '';
        $node = $n->item(0);
        return ($node instanceof DOMAttr) ? trim((string) $node->value) : $this->textContent($node);
    }

    private function extractBlockByHeading(DOMXPath $xp, string $heading): string
    {
        $nodeList = $xp->query("//span[@class='h_abschnitt' and normalize-space()='{$heading}']/parent::*[1]");
        if (!$nodeList || !$nodeList->length) return '';

        $container = $nodeList->item(0);
        $out = [];
        foreach ($container->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'span' && $child->getAttribute('class') === 'h_abschnitt') continue;
            if ($child instanceof DOMElement && $child->tagName === 'br') continue;
            $txt = $this->textContent($child);
            if ($txt !== '') $out[] = $txt;
        }
        $joined = trim(implode(', ', $out));
        $joined = preg_replace('/,\s*,/u', ',', $joined);
        return trim($joined, ", \t\n\r");
    }

    private function parseDateTime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        $raw = str_replace(["\xC2\xA0", '&nbsp;', 'Uhr', '–', '—'], [' ', ' ', '', '-', '-'], $raw);
        $raw = preg_replace('/\s+/u', ' ', $raw);

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
