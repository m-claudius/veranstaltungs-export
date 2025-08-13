<?php
/**
 * Parser für "Musik in alten Heidekirchen"
 * Liest die Übersicht /termine und parst jede Detail-Seite.
 */
class MusikInAltenHeidekirchenParser
{
    const BASE_URL = 'https://musik-in-alten-heidekirchen.wir-e.de';
    const LIST_URL = 'https://musik-in-alten-heidekirchen.wir-e.de/termine';

    /** Öffentliche API: Übersicht crawlen und Events arrayweise liefern. */
    public function crawl(string $listUrl = self::LIST_URL, int $max = 50): array
    {
        $html = $this->fetch($listUrl);
        if ($html === '') {
            error_log('[MusikHeide] Listen-Seite leer: ' . $listUrl);
            return [];
        }

        $links = $this->extractDetailLinks($html);
        if ($max > 0) {
            $links = array_slice($links, 0, $max);
        }

        $events = [];
        foreach ($links as $url) {
            $detailHtml = $this->fetch($url);
            if ($detailHtml === '') {
                error_log('[MusikHeide] Detail leer: ' . $url);
                continue;
            }
            $events[] = $this->parseDetail($detailHtml, $url);
        }

        return $events;
    }

    /** Robust fetch über WP HTTP API (Fallback file_get_contents). */
    private function fetch(string $url): string
    {
        $args = [
            'timeout'     => 15,
            'redirection' => 5,
            'headers'     => [
                'User-Agent' => 'KSE-Exporter/2.0 (+WordPress; ' . home_url() . ')',
                'Accept'     => 'text/html,application/xhtml+xml',
            ],
        ];

        if (function_exists('wp_remote_get')) {
            $res = wp_remote_get($url, $args);
            if (!is_wp_error($res)) {
                return (string) wp_remote_retrieve_body($res);
            }
        }

        // Fallback (CLI / Notfall)
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 15,
                'header'  => "User-Agent: KSE-Exporter/2.0\r\nAccept: text/html\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body !== false ? (string) $body : '';
    }

    /** Alle Detail-URLs aus der Listen-Seite ziehen. */
    private function extractDetailLinks(string $html): array
    {
        $links = [];
        // einfache, stabile Regel: alle href="/termine/<uuid>"
        if (preg_match_all('#href="(/termine/[a-f0-9\-]{10,})"#i', $html, $m)) {
            foreach ($m[1] as $path) {
                $links[] = $this->absUrl($path);
            }
        }
        // Duplikate entfernen / normalisieren
        $links = array_values(array_unique($links));
        return $links;
    }

    /** Eine Detail-Seite in strukturierte Felder zerlegen. */
    private function parseDetail(string $html, string $url): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // sicherstellen, dass Umlaute korrekt landen
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        // --- Titel (H2, nicht <title>) ---
        $title = $this->xpText($xp, "//div[contains(@class,'event-occurrence')]//h2");
        $title = $title !== '' ? $title : $this->xpText($xp, "//h2");

        // --- Datum/Zeit ---
        $dateRaw = $this->xpText($xp, "//div[contains(@class,'event-occurrence')]//h4[contains(@class,'date')]");
        $startIso = $this->parseDateTime($dateRaw);

        // --- Bild (src oder größtes srcset) ---
        $imgNode = $xp->query("//figure[contains(@class,'teaser-image')]//img")->item(0);
        $image = '';
        if ($imgNode instanceof DOMElement) {
            $src = $imgNode->getAttribute('src');
            if ($src) {
                $image = $this->absUrl($src);
            } else {
                $srcset = $imgNode->getAttribute('srcset');
                $image = $this->pickLargestFromSrcset($srcset);
            }
        }
        // Sicherstellen, dass wir wirklich ein absolutes Bild haben
        if ($image && str_starts_with($image, '/')) {
            $image = $this->absUrl($image);
        }

        // --- Beschreibung (alles unterhalb des Bildes im Event-Content) ---
        // Nimmt alle <div>s im .event-content, ignoriert figure/image-container.
        $desc = $this->collectEventText($xp);

        // --- Veranstaltungsort ---
        $venueName = '';
        $venueAddr = '';
        $venueBlock = $xp->query("//h4[normalize-space()='Veranstaltungsort']/following::div[contains(@class,'address')][1]")->item(0);
        if ($venueBlock instanceof DOMElement) {
            $strong = $venueBlock->getElementsByTagName('strong')->item(0);
            if ($strong) {
                $venueName = trim($strong->textContent);
            }
            // Adresse: alle <p> Texte (ohne <strong>) zusammenführen
            $pTexts = [];
            foreach ($venueBlock->getElementsByTagName('p') as $p) {
                $t = trim(preg_replace('/\s+/', ' ', $p->textContent));
                if ($t !== '' && stripos($t, $venueName) === false) {
                    $pTexts[] = $t;
                }
            }
            // Manche Seiten haben <br> statt <p>; fallback:
            if (!$pTexts) {
                $t = trim(preg_replace('/\s+/', ' ', $venueBlock->textContent));
                if ($t !== '' && $venueName !== '' && str_starts_with($t, $venueName)) {
                    $t = trim(substr($t, strlen($venueName)));
                }
                if ($t !== '') $pTexts[] = $t;
            }
            $venueAddr = trim(implode(' | ', $pTexts), " \t\n\r\0\x0B|");
        }

        // --- Eintritt / Preise ---
        $prices = '';
        foreach ($xp->query("//div[contains(@class,'text-element-body')]") as $el) {
            $txt = trim($el->textContent);
            if (stripos($txt, 'Eintrittspreise') !== false) {
                $prices = $this->normalizeSpace($txt);
                break;
            }
        }

        // Optional: Copyright des Bildes (steht oft direkt unter dem Bild)
        $imgCredit = $this->xpText($xp, "//figure[contains(@class,'teaser-image')]//div[contains(@class,'source')]");

        return [
            'title'        => $this->normalizeSpace($title),
            'start'        => $startIso,
            'end'          => null,                           // keine Endzeit auf der Seite
            'venue'        => $venueName,
            'address'      => $venueAddr,
            'description'  => $desc,
            'prices'       => $prices,
            'image'        => $image,
            'image_credit' => $imgCredit,
            'source_url'   => $url,
        ];
    }

    // ------- Helfer -------

    private function pickLargestFromSrcset(string $srcset): string
    {
        // „url 196w, url 512w, …“ -> nehme größte Breite
        $best = '';
        $bestW = 0;
        foreach (array_map('trim', explode(',', $srcset)) as $part) {
            if ($part === '') continue;
            if (preg_match('#\s+(\d+)w$#', $part, $m)) {
                $w = (int)$m[1];
                $url = trim(substr($part, 0, -strlen($m[0])));
                if ($w > $bestW) {
                    $bestW = $w;
                    $best = $url;
                }
            } else {
                // kein width-hint -> nimm als Fallback
                $best = $part;
            }
        }
        return $best ? $this->absUrl($best) : '';
    }

    /** Sammelt humanen Text aus dem Event-Content, aber ohne Bild/figure. */
    private function collectEventText(DOMXPath $xp): string
    {
        $buf = [];

        // 1) <div class="event-content clearfix"> … </div>
        $root = $xp->query("//div[contains(@class,'event-content')]")->item(0);
        if (!$root instanceof DOMElement) return '';

        // figure entfernen
        foreach ($root->getElementsByTagName('figure') as $fig) {
            $fig->parentNode?->removeChild($fig);
        }

        // alle direkten/div-Kinder als Zeilen zusammenführen
        foreach ($root->getElementsByTagName('div') as $div) {
            $txt = trim($div->textContent);
            if ($txt !== '') $buf[] = $this->normalizeSpace($txt);
        }

        // Falls das leer blieb, nimm einfach den reinen Text des Containers
        if (!$buf) {
            $buf[] = $this->normalizeSpace($root->textContent);
        }

        // HTML-entities & Mehrfach-Leerzeichen normalisieren
        $desc = implode("\n\n", array_filter($buf, fn($t) => $t !== ''));
        return $desc;
    }

    private function parseDateTime(string $raw): ?string
    {
        // Beispiele: "17.08.2025 / 17:00"  oder  "17.08.2025/17:00"
        if (preg_match('#(\d{2})\.(\d{2})\.(\d{4})\s*/\s*(\d{2}):(\d{2})#', $raw, $m)) {
            $iso = sprintf('%04d-%02d-%02d %02d:%02d:00', (int)$m[3], (int)$m[2], (int)$m[1], (int)$m[4], (int)$m[5]);
            return $iso;
        }
        // Fallback: nur Datum
        if (preg_match('#(\d{2})\.(\d{2})\.(\d{4})#', $raw, $m)) {
            return sprintf('%04d-%02d-%02d 00:00:00', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        return null;
    }

    private function xpText(DOMXPath $xp, string $xpath): string
    {
        $n = $xp->query($xpath)->item(0);
        return $n ? $this->normalizeSpace($n->textContent) : '';
    }

    private function normalizeSpace(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace("/[ \t\x{00A0}]+/u", ' ', $s);
        $s = preg_replace("/\h*\R\h*/u", "\n", $s); // Zeilen umbrechen
        return trim($s);
    }

    private function absUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return rtrim(self::BASE_URL, '/') . '/' . ltrim($path, '/');
    }
}
