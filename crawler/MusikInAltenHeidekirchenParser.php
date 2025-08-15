<?php
/**
 * Parser „Musik in alten Heidekirchen“ – stabile (alte) Variante
 * - Liest /termine
 * - Holt alle Detail-Links („/termine/<uuid>“)
 * - Parst pro Detailseite: Titel (H2), Datum/Zeit (H4.date), Bild (figure.teaser-image > img oder og:image),
 *   Beschreibung (.event-content), Ort/Adresse (.address – falls vorhanden)
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('MusikInAltenHeidekirchenParser')) {

class MusikInAltenHeidekirchenParser
{
    public const BASE     = 'https://musik-in-alten-heidekirchen.wir-e.de';
    public const LIST_URL = 'https://musik-in-alten-heidekirchen.wir-e.de/termine';
    public const MAX_ITEMS = 30;
    private const UA      = 'KSE-EventCrawler/2.0 (+WordPress)';

    /** Öffentlicher Einstieg */
    public function crawl(string $listUrl = self::LIST_URL, int $limit = self::MAX_ITEMS): array
    {
        $html = $this->get($listUrl);
        if ($html === '') return [];

        $links = $this->extractDetailLinks($html);
        if (!$links) return [];

        $events = [];
        foreach (array_slice($links, 0, max(1,$limit)) as $u) {
            $detail = $this->get($u);
            if ($detail === '') continue;

            $ev = $this->parseDetail($detail, $u);
            if (!empty($ev['title'])) $events[] = $ev;
        }
        return $events;
    }

    /* ==================== intern ==================== */

    private function get(string $url): string
    {
        // WP-HTTP
        $res = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'User-Agent'      => self::UA,
                'Accept'          => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
            ],
            'redirection' => 5,
        ]);
        if (is_wp_error($res)) return '';
        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) return '';
        $body = wp_remote_retrieve_body($res);
        return is_string($body) ? $body : '';
    }

    /** @return array{0:DOMDocument,1:DOMXPath}|null */
    private function dom(string $html)
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
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
        return rtrim(self::BASE,'/').'/'.ltrim($href,'/');
    }

    private function text(DOMXPath $xp, string $q): string
    {
        $n = $xp->query($q);
        if (!$n || !$n->length) return '';
        $t = $n->item(0)->textContent ?? '';
        $t = preg_replace('/\s+/u', ' ', (string)$t);
        return trim($t);
    }

    private function extractDetailLinks(string $listHtml): array
    {
        [$doc, $xp] = $this->dom($listHtml);
        $out = [];

        // alle <a href> die /termine/<uuid> enthalten (keine Paginierung/Navigation)
        foreach ($xp->query("//a[@href]") as $a) {
            /** @var DOMElement $a */
            $href = trim($a->getAttribute('href'));
            if ($href === '') continue;
            if (preg_match('#/termine/[a-f0-9\-]{16,}$#i', $href)) {
                $out[] = $this->abs($href);
            }
        }
        return array_values(array_unique($out));
    }

    private function parseDetail(string $html, string $url): array
    {
        [$doc, $xp] = $this->dom($html);

        // ===== Titel – bewährter Selektor: H2 im Eventblock =====
        $title = '';
        foreach ([
            "//div[contains(@class,'event')][contains(@class,'occurrence')]//h2[normalize-space()][1]",
            "//main//h2[normalize-space()][1]",
            "//h2[normalize-space()][1]"
        ] as $q) {
            $title = $this->text($xp, $q);
            if ($title !== '') break;
        }

        // ===== Datum/Zeit – bewährter Selektor: H4.date =====
        $dateText = $this->text($xp, "//h4[contains(@class,'date')][1]");
        [$start, $end] = $this->parseDateTime($dateText);

        // ===== Bild – zuerst teaser-image, dann og:image, dann erstes <main> Bild =====
        $image = '';
        $img = $xp->query("(//figure[contains(@class,'teaser')]/img[@src] | //figure[contains(@class,'teaser')]//img[@src])[1]");
        if ($img && $img->length) {
            $image = $this->abs($img->item(0)->getAttribute('src'));
        }
        if ($image === '') {
            $og = $this->text($xp, "//meta[@property='og:image' or @name='og:image']/@content");
            if ($og) $image = $this->abs($og);
        }
        if ($image === '') {
            $img2 = $xp->query("(//main//img[@src])[1]");
            if ($img2 && $img2->length) $image = $this->abs($img2->item(0)->getAttribute('src'));
        }

        // ===== Beschreibung – .event-content (reiner Text) =====
        $desc = '';
        $node = $xp->query("//div[contains(@class,'event-content')][1]");
        if ($node && $node->length) {
            $desc = trim(preg_replace('/\s+/u', ' ', $node->item(0)->textContent ?? ''));
        }

        // ===== Ort/Adresse – .address (falls vorhanden) =====
        $loc = '';
        $addr = $xp->query("(//div[contains(@class,'address')])[1]");
        if ($addr && $addr->length) {
            $loc = trim(preg_replace('/\s+/u',' ', $addr->item(0)->textContent ?? ''));
        }

        // Fallback: JSON-LD Event (falls vorhanden)
        if ($title === '' || $start === '' || $image === '' || $desc === '') {
            foreach ($xp->query("//script[@type='application/ld+json']") as $sc) {
                $json = json_decode($sc->textContent ?? '', true);
                if (!is_array($json)) continue;
                $obj = isset($json['@type']) ? $json : ( (isset($json[0]) && is_array($json[0])) ? $json[0] : null );
                if (!$obj || !isset($obj['@type'])) continue;
                if (stripos($obj['@type'], 'Event') === false) continue;

                $title = $title ?: ($obj['name'] ?? '');
                if (!empty($obj['startDate'])) {
                    try { $start = (new DateTime($obj['startDate']))->format('Y-m-d H:i:s'); } catch(\Throwable $e) {}
                }
                $desc  = $desc ?: ($obj['description'] ?? '');
                $image = $image ?: (is_array($obj['image']) ? ($obj['image'][0] ?? '') : ($obj['image'] ?? ''));
                if (empty($loc) && !empty($obj['location']['name'])) {
                    $loc = $obj['location']['name'];
                    if (!empty($obj['location']['address'])) {
                        $addrTxt = is_array($obj['location']['address']) ? implode(' ', $obj['location']['address']) : $obj['location']['address'];
                        $loc .= ' ' . $addrTxt;
                    }
                }
                break;
            }
        }

        return [
            'source'      => 'Musik in alten Heidekirchen',
            'source_url'  => $url,
            'title'       => $title,
            'start'       => $start,
            'end'         => $end,
            'image'       => $image,
            'description' => $desc,
            'location'    => $loc,
        ];
    }

    private function parseDateTime(string $raw): array
    {
        $raw = trim(str_replace(['Uhr','–','—','|'], ['','','-','-'], $raw));
        $raw = preg_replace('/\s+/u', ' ', $raw);

        $date = '';
        if (preg_match('/(\d{2}\.\d{2}\.\d{4})/u', $raw, $m)) $date = $m[1];

        $times = [];
        if (preg_match_all('/\b(\d{1,2}:\d{2})\b/u', $raw, $mm)) $times = $mm[1];

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
}

}
