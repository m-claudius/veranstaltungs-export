<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Parser für "Musik in alten Heidekirchen"
 * Listenseite: https://musik-in-alten-heidekirchen.wir-e.de/termine
 * Detailseite: /termine/<uuid>
 */
class MusikInAltenHeidekirchenParser
{
    const LIST_URL = 'https://musik-in-alten-heidekirchen.wir-e.de/termine';

    /** Haupt-Einstieg: Liste -> Detail -> Events */
    public static function crawl($listUrl = self::LIST_URL, $max = 10) {
        $listUrl = trim($listUrl) ?: self::LIST_URL;
        $html = self::fetch($listUrl);
        if ($html === '') return [];

        $links = self::extractDetailLinks($html);
        $links = array_slice(array_unique($links), 0, max(1, (int)$max));

        $out = [];
        foreach ($links as $u) {
            $dhtml = self::fetch($u);
            if ($dhtml === '') continue;
            $ev = self::parseDetail($dhtml, $u);
            if (!empty($ev['title'])) $out[] = $ev;
        }
        return $out;
    }

    /* ================= HTTP ================= */

    private static function fetch($url) {
        $resp = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'de-DE,de;q=0.9',
            ]
        ]);
        if (is_wp_error($resp)) {
            error_log('MIaH fetch error: '.$url.' -> '.$resp->get_error_message());
            return '';
        }
        $body = wp_remote_retrieve_body($resp);
        if (!is_string($body) || $body === '') return '';
        return self::normalizeHtml($body);
    }

    private static function normalizeHtml($html) {
        if (!mb_detect_encoding($html, 'UTF-8', true)) {
            $html = mb_convert_encoding($html, 'UTF-8', 'auto');
        }
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = preg_replace('~<\s*br\s*/?>~i', "\n", $html);
        return $html;
    }

    /* ============== LISTEN-SEITE ============== */

    private static function extractDetailLinks($html) {
        $links = [];

        if (preg_match_all('~href="(/termine/[0-9a-f\-]{10,})"~i', $html, $m)) {
            foreach ($m[1] as $path) $links[] = self::absUrl($path);
        }

        if (empty($links)) {
            $xp = self::xpath($html);
            foreach ($xp->query('//a[contains(@href,"/termine/")]') as $a) {
                /** @var DOMElement $a */
                $href = $a->getAttribute('href');
                if ($href && strpos($href, '/termine/') !== false) $links[] = self::absUrl($href);
            }
        }
        return $links;
    }

    private static function absUrl($href) {
        if (preg_match('~^https?://~i', $href)) return $href;
        return 'https://musik-in-alten-heidekirchen.wir-e.de/' . ltrim($href, '/');
    }

    /* ============== DETAIL-SEITE ============== */

    private static function parseDetail($html, $sourceUrl) {
        // relevanten Chunk isolieren
        $chunk = self::sliceBetween($html, "</turbo-frame>", "<div class='social-bar'>");
        if ($chunk === '') $chunk = $html;

        $xp = self::xpath($chunk);

        // Titel
        $title = self::textOf($xp, "//div[contains(@class,'event-occurrence')]//h2");
        if ($title === '') $title = self::rx1('~<h2>\s*(.*?)\s*</h2>~is', $chunk);
        $title = self::clean($title);

        // Datum/Zeit
        $dateRaw = self::textOf($xp, "//h4[contains(@class,'date')]");
        if ($dateRaw === '') $dateRaw = self::rx1('~<h4[^>]*class=[\'"][^\'"]*date[^\'"]*[\'"][^>]*>\s*(.*?)\s*</h4>~is', $chunk);
        $start = self::parseGermanDateTime($dateRaw);

        // Beschreibung (ohne figure)
        $descHtml = '';
        $n = $xp->query("//div[contains(@class,'event-content')]");
        if ($n && $n->length) {
            $tmp = $n->item(0)->C14N();
            $tmp = preg_replace('~<figure.*?</figure>~is', '', $tmp);
            $descHtml = $tmp;
        } else {
            $descHtml = self::sliceBetween($chunk, "<div class='event-content", "<div class='event-details");
        }
        $description = self::htmlToText($descHtml);

        // Veranstaltungsort-Block
        $venueHtml = self::sliceBetween($chunk, "<h4>Veranstaltungsort</h4>", "</div></div></div></div>");
        if ($venueHtml === '') {
            $venueHtml = self::innerHtmlOf($xp, "//h4[normalize-space()='Veranstaltungsort']/following::div[contains(@class,'address')][1]");
        }
        $venue = $venueHtml !== '' ? self::htmlToText($venueHtml) : '';
        $venue = preg_replace('~^\s*Veranstaltungsort\s*~i', '', $venue);
        $venue = preg_replace('~\n{2,}~', "\n", $venue);

        // Bild (src oder srcset)
        $image = '';
        $img = $xp->query("//figure[contains(@class,'teaser-image')]//img")->item(0);
        if ($img instanceof DOMElement) {
            $image = trim($img->getAttribute('src'));
            if ($image === '' && $img->hasAttribute('srcset')) {
                $ss = $img->getAttribute('srcset');
                $parts = preg_split('~\s*,\s*~', $ss);
                $last = trim(end($parts));
                $image = preg_replace('~\s+\d+w$~', '', $last);
            }
        }
        if ($image === '') {
            $image = self::rx1('~<img[^>]+src="([^"]+)"~i', $chunk);
            if ($image === '') {
                $ss = self::rx1('~srcset="([^"]+)"~i', $chunk);
                if ($ss !== '') {
                    $parts = preg_split('~\s*,\s*~', $ss);
                    $last  = trim(end($parts));
                    $image = preg_replace('~\s+\d+w$~', '', $last);
                }
            }
        }

        return [
            'title'       => $title ?: '—',
            'start'       => $start ?: null,
            'venue'       => $venue,
            'description' => $description,
            'image'       => $image,
            'source'      => $sourceUrl,
        ];
    }

    /* ================= Utils ================= */

    private static function xpath($html) {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html);
        libxml_clear_errors();
        return new DOMXPath($doc);
    }

    private static function sliceBetween($haystack, $start, $end) {
        $p1 = stripos($haystack, $start);
        if ($p1 === false) return '';
        $p1 += strlen($start);
        $p2 = stripos($haystack, $end, $p1);
        if ($p2 === false) return substr($haystack, $p1);
        return substr($haystack, $p1, $p2 - $p1);
    }

    private static function innerHtmlOf(DOMXPath $xp, $xpath) {
        $n = $xp->query($xpath);
        if (!$n || !$n->length) return '';
        return $n->item(0)->C14N();
    }

    private static function textOf(DOMXPath $xp, $xpath) {
        $n = $xp->query($xpath);
        if (!$n || !$n->length) return '';
        return self::clean($n->item(0)->textContent ?? '');
    }

    private static function rx1($pattern, $subject) {
        if (preg_match($pattern, $subject, $m)) return self::clean($m[1]);
        return '';
    }

    private static function htmlToText($html) {
        if ($html === '') return '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("~[ \t]+\n~", "\n", $text);
        $text = preg_replace("~\n{3,}~", "\n\n", $text);
        return trim($text);
    }

    private static function clean($s) {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('~[^\PC\s]~u', '', $s);
        return trim(preg_replace('~\s+~u', ' ', $s));
    }

    private static function parseGermanDateTime($raw) {
        $raw = trim((string)$raw);
        if ($raw === '') return null;
        $raw2 = preg_replace('~[A-Za-zäöüÄÖÜ\.\,]+~u', ' ', $raw); // Wochentage, „Uhr“, Kommas entfernen
        $raw2 = preg_replace('~\s+~', ' ', $raw2);

        if (preg_match('~(\d{2}\.\d{2}\.\d{4}).*?(\d{1,2}:\d{2})~', $raw2, $m)) {
            $tz = wp_timezone_string() ?: 'Europe/Berlin';
            $d  = DateTime::createFromFormat('d.m.Y H:i', $m[1].' '.$m[2], new DateTimeZone($tz));
            if ($d) return $d->format('Y-m-d H:i:s');
        }
        if (preg_match('~(\d{2}\.\d{2}\.\d{4})~', $raw2, $m)) {
            $tz = wp_timezone_string() ?: 'Europe/Berlin';
            $d  = DateTime::createFromFormat('d.m.Y H:i', $m[1].' 00:00', new DateTimeZone($tz));
            if ($d) return $d->format('Y-m-d H:i:s');
        }
        return null;
    }
}

/* =========================================================
 * ADMIN-RENDERER (UI) – wird vom Menü aufgerufen
 * ========================================================= */
if (!function_exists('kse_render_musikheide_parser_admin')) {
    function kse_render_musikheide_parser_admin() {
        error_log('MIaH admin renderer loaded'); // Sichtbarer Nachweis im debug.log

        $default_url = MusikInAltenHeidekirchenParser::LIST_URL;

        $list_url = isset($_POST['kse_musik_list_url'])
            ? esc_url_raw(trim($_POST['kse_musik_list_url']))
            : $default_url;

        $limit = isset($_POST['kse_musik_limit'])
            ? max(1, min(50, intval($_POST['kse_musik_limit'])))
            : 10;

        $results = [];
        if (isset($_POST['kse_musik_load'])) {
            $results = MusikInAltenHeidekirchenParser::crawl($list_url, $limit);
        }

        ?>
        <div class="wrap">
            <h1>Musik in alten Heidekirchen – Parser</h1>

            <form method="post">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="kse_musik_list_url">Listen-URL</label></th>
                        <td>
                            <input type="url" name="kse_musik_list_url" id="kse_musik_list_url"
                                   class="regular-text code" style="width: 600px"
                                   value="<?php echo esc_attr($list_url); ?>" />
                            <p class="description">Standard: <?php echo esc_html($default_url); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kse_musik_limit">Max. Anzahl</label></th>
                        <td>
                            <input type="number" name="kse_musik_limit" id="kse_musik_limit"
                                   min="1" max="50" value="<?php echo (int)$limit; ?>" />
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" name="kse_musik_load" class="button button-primary">
                        Vorschau laden
                    </button>
                </p>
            </form>

            <h2 class="title">Vorschau (<?php echo count($results); ?>)</h2>
            <table class="widefat fixed striped">
                <thead>
                <tr>
                    <th style="width:28%">Titel</th>
                    <th style="width:12%">Start</th>
                    <th style="width:18%">Ort</th>
                    <th style="width:32%">Beschreibung</th>
                    <th style="width:10%">Bild</th>
                    <th style="width:5%">Quelle</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($results)): ?>
                    <tr><td colspan="6">Noch keine Daten. Klicke oben auf „Vorschau laden“.</td></tr>
                <?php else: foreach ($results as $ev): ?>
                    <tr>
                        <td><?php echo esc_html($ev['title']); ?></td>
                        <td><?php echo esc_html($ev['start']); ?></td>
                        <td><?php echo esc_html($ev['venue']); ?></td>
                        <td><?php echo esc_html($ev['description']); ?></td>
                        <td>
                            <?php if (!empty($ev['image'])): ?>
                                <img src="<?php echo esc_url($ev['image']); ?>"
                                     alt="" style="max-width:90px;height:auto;border:1px solid #ddd;padding:2px;background:#fff" />
                            <?php endif; ?>
                        </td>
                        <td><a href="<?php echo esc_url($ev['source']); ?>" target="_blank" rel="noopener">öffnen</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
