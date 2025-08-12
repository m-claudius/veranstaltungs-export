<?php
/**
 * Parser für "Musik in alten Heidekirchen"
 * Liest die Terminliste und einzelne Terminseiten.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MusikInAltenHeidekirchenParser
{
    const BASE_URL = 'https://musik-in-alten-heidekirchen.wir-e.de';
    const LIST_URL = 'https://musik-in-alten-heidekirchen.wir-e.de/termine';

    /**
     * Hole Event-Detail-Links von der Listen-Seite.
     * @param string $list_url
     * @return string[] absolute URLs
     */
    public function get_event_links($list_url = self::LIST_URL): array
    {
        $html = $this->fetch($list_url);
        if (!$html) {
            return [];
        }

        // Links der Form /termine/<uuid> einsammeln
        $links = [];
        if (preg_match_all('#href="([^"]*/termine/[a-f0-9-]{36}[^"]*)"#i', $html, $m)) {
            foreach (array_unique($m[1]) as $u) {
                $links[] = $this->absolutize($u);
            }
        }

        // Fallback: nach "mehr" Links schauen
        if (empty($links) && preg_match_all('#<a[^>]+href="([^"]+)"[^>]*>\s*(mehr|weiter|details)\s*</a>#i', $html, $mm)) {
            foreach (array_unique($mm[1]) as $u) {
                if (strpos($u, '/termine/') !== false) {
                    $links[] = $this->absolutize($u);
                }
            }
        }

        return $links;
    }

    /**
     * Parse eine Event-Detailseite.
     * @param string $url
     * @return array|null Normierte Event-Daten
     */
    public function parse_event(string $url): ?array
    {
        $html = $this->fetch($url);
        if (!$html) {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // Workaround für Umlaute in loadHTML
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xp = new DOMXPath($dom);

        // 1) JSON-LD (falls vorhanden) bevorzugen
        $json = $this->extract_json_ld_event($xp);

        // 2) Fallback-Selektoren lt. bereitgestelltem HTML-Ausschnitt
        $title = $this->text($xp, "//h1 | //h2");
        if (!$title && $json && !empty($json['name'])) {
            $title = trim($json['name']);
        }

        $dateText = $this->text($xp, "//h4[contains(@class,'date')]");
        [$start, $end] = $this->parseDateRange($dateText, $json);

        // Beschreibung
        $descriptionHtml = $this->innerHTML($xp, "(//*[@class='content-elements']//div[contains(@class,'text-element-body')])[1]");
        if (!$descriptionHtml && $json && !empty($json['description'])) {
            $descriptionHtml = wp_kses_post(is_array($json['description']) ? implode("\n", $json['description']) : $json['description']);
        }

        // Ort
        $location = $this->text($xp, "//*[contains(@class,'event')]//*[contains(@class,'location')]//text() | //*[contains(@class,'address')]//text()");
        if (!$location && $json && !empty($json['location'])) {
            if (is_array($json['location'])) {
                if (!empty($json['location']['name'])) {
                    $location = $json['location']['name'];
                } elseif (!empty($json['location'][0]['name'])) {
                    $location = $json['location'][0]['name'];
                }
            } elseif (is_string($json['location'])) {
                $location = $json['location'];
            }
        }
        $location = trim(preg_replace('/\s+/', ' ', (string)$location));

        // Bild
        $image = $this->attr($xp, "(//figure[contains(@class,'teaser-image')]//img)[1]", "src");
        if (!$image) {
            $style = $this->attr($xp, "(//figure[contains(@class,'teaser-image')]//*[contains(@style,'background')])[1]", "style");
            if ($style && preg_match('#url\\(([^)]+)\\)#', $style, $mm)) {
                $image = trim($mm[1], "'\" ");
            }
        }
        if (!$image && $json && !empty($json['image'])) {
            $image = is_array($json['image']) ? reset($json['image']) : $json['image'];
        }
        if ($image && strpos($image, 'http') !== 0) {
            $image = $this->absolutize($image);
        }

        return [
            'source'      => $url,
            'post_title'  => $title ?: '',
            'event_start' => $start ?: '',
            'event_end'   => $end ?: '',
            'location'    => $location ?: '',
            'image'       => $image ?: '',
            'description' => $descriptionHtml ?: '',
        ];
    }

    /**
     * Vorschau mehrerer Events
     */
    public function preview(int $limit = 20, string $list_url = self::LIST_URL): array
    {
        $links  = array_slice($this->get_event_links($list_url), 0, max(1, $limit));
        $events = [];
        foreach ($links as $u) {
            $ev = $this->parse_event($u);
            if ($ev) {
                $events[] = $ev;
            }
        }
        return $events;
    }

    /* ====================== Helfer ====================== */

    private function fetch(string $url): string
    {
        $res = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => ['Accept' => 'text/html,application/xhtml+xml']
        ]);
        if (is_wp_error($res)) {
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code >= 400) {
            return '';
        }
        return (string) wp_remote_retrieve_body($res);
    }

    private function absolutize(string $url): string
    {
        if (strpos($url, 'http') === 0) {
            return $url;
        }
        return rtrim(self::BASE_URL, '/') . '/' . ltrim($url, '/');
    }

    private function text(DOMXPath $xp, string $xpath): string
    {
        $n = $xp->query($xpath)->item(0);
        if (!$n) {
            return '';
        }
        return trim(preg_replace('/\s+/', ' ', $n->textContent));
    }

    private function attr(DOMXPath $xp, string $xpath, string $attr): string
    {
        $n = $xp->query($xpath)->item(0);
        return $n ? trim($n->getAttribute($attr)) : '';
    }

    private function innerHTML(DOMXPath $xp, string $xpath): string
    {
        $n = $xp->query($xpath)->item(0);
        if (!$n) {
            return '';
        }
        $html = '';
        foreach ($n->childNodes as $child) {
            $html .= $n->ownerDocument->saveHTML($child);
        }
        return $html;
    }

    private function extract_json_ld_event(DOMXPath $xp): ?array
    {
        foreach ($xp->query("//script[@type='application/ld+json']") as $node) {
            $data = json_decode($node->textContent, true);
            if (!$data) {
                continue;
            }
            // Direktes Event
            if (!empty($data['@type']) && (stripos($data['@type'], 'Event') !== false || $data['@type'] === 'Event')) {
                return $data;
            }
            // Graph
            if (!empty($data['@graph']) && is_array($data['@graph'])) {
                foreach ($data['@graph'] as $g) {
                    if (!empty($g['@type']) && (stripos($g['@type'], 'Event') !== false || $g['@type'] === 'Event')) {
                        return $g;
                    }
                }
            }
        }
        return null;
    }

    private function parseDateRange(string $dateText, ?array $json): array
    {
        $start = '';
        $end   = '';

        if ($json) {
            if (!empty($json['startDate'])) {
                $start = $this->normalizeDate($json['startDate']);
            }
            if (!empty($json['endDate'])) {
                $end = $this->normalizeDate($json['endDate']);
            }
        }

        if (!$start && $dateText) {
            // Beispiele: "17.08.2025 / 17:00" oder "17.08.2025"
            if (preg_match('#(\d{1,2})\.(\d{1,2})\.(\d{4}).*?(\d{1,2}:\d{2})#', $dateText, $m)) {
                $d = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
                $start = $d . ' ' . $m[4] . ':00';
            } elseif (preg_match('#(\d{1,2})\.(\d{1,2})\.(\d{4})#', $dateText, $m)) {
                $start = sprintf('%04d-%02d-%02d 00:00:00', (int)$m[3], (int)$m[2], (int)$m[1]);
            }
        }

        return [$start, $end];
    }

    private function normalizeDate(string $s): string
    {
        $s = trim($s);
        // ISO
        if (preg_match('#^\d{4}-\d{2}-\d{2}#', $s)) {
            $s = str_replace('T', ' ', $s);
            if (strlen($s) === 10) {
                $s .= ' 00:00:00';
            } elseif (strlen($s) === 16) {
                $s .= ':00';
            }
            return $s;
        }
        // DE
        if (preg_match('#(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s+(\d{1,2}:\d{2}))?#', $s, $m)) {
            $d = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
            return $d . ' ' . (!empty($m[4]) ? $m[4] . ':00' : '00:00:00');
        }
        return $s;
    }
}
