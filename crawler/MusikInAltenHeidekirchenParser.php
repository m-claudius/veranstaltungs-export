<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Parser für „Musik in alten Heidekirchen“ (wir-e.de)
 * - Holt die Listen-Seite
 * - Sammelt Detail-Links
 * - Liest pro Detailseite bevorzugt JSON-LD (Schema.org Event)
 * - Fallbacks: <meta property="og:*">, <h1>, erster <img>, etc.
 */
class MusikInAltenHeidekirchenParser
{
    // Standard-Listen-URL
    public const LIST_URL = 'https://musik-in-alten-heidekirchen.wir-e.de/termine';

    /** Kleiner Helfer: saubere User-Agent + Timeout */
    private static function fetch_url(string $url): string
    {
        $resp = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'User-Agent' => 'KSE-EventCrawler/2.0 (+WP)'
            ],
        ]);
        if (is_wp_error($resp)) {
            return '';
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return '';
        }
        return (string)wp_remote_retrieve_body($resp);
    }

    /** Absolute URL bauen */
    private static function abs_url(string $href, string $base): string
    {
        if (preg_match('~^https?://~i', $href)) return $href;
        // remove double slashes
        if (str_starts_with($href, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $href;
        }
        // build from base
        $p = wp_parse_url($base);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return $href;
        $root = $p['scheme'] . '://' . $p['host'];
        if (!empty($p['port'])) $root .= ':' . $p['port'];
        if (str_starts_with($href, '/')) {
            return $root . $href;
        }
        // relative Pfad -> an Pfad des base anhängen
        $dir = rtrim(dirname($p['path'] ?? '/'), '/') . '/';
        return $root . $dir . $href;
    }

    /** Aus der Listen-Seite alle Detail-Links holen */
    private static function extract_detail_links(string $html, string $base_url, int $limit = 10): array
    {
        $links = [];

        // 1) DOM suchen: alle <a href="/termine/<uuid>">
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html);
        libxml_clear_errors();

        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = $a->getAttribute('href');
            if (!$href) continue;

            // wir-e Detailseiten sehen i. d. R. so aus: /termine/<uuid>
            if (preg_match('~\/termine\/[a-z0-9-]{10,}~i', $href)) {
                $abs = self::abs_url($href, $base_url);
                $links[$abs] = true;
            }
        }

        // 2) Fallback Regex (falls DOM mal nicht alle findet)
        if (empty($links)) {
            if (preg_match_all('~href=["\']([^"\']*\/termine\/[a-z0-9-]{10,}[^"\']*)["\']~i', $html, $m)) {
                foreach ($m[1] as $href) {
                    $abs = self::abs_url($href, $base_url);
                    $links[$abs] = true;
                }
            }
        }

        $out = array_keys($links);
        // Duplikate los & Limit
        $out = array_values(array_unique($out));
        if ($limit > 0) {
            $out = array_slice($out, 0, $limit);
        }
        return $out;
    }

    /** JSON-LD (Schema.org) aus Detailseite ziehen (Event-Objekt) */
    private static function extract_event_from_jsonld(string $html): ?array
    {
        // Alle JSON-LD Blöcke
        if (!preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $blocks)) {
            return null;
        }

        foreach ($blocks[1] as $json) {
            $json = trim($json);

            // Häufig sind HTML-Entities drin
            $json = html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $data = json_decode($json, true);
            if (!$data) continue;

            // Kann Liste oder Objekt sein
            $candidates = is_array($data) && isset($data[0]) ? $data : [$data];

            foreach ($candidates as $obj) {
                if (!is_array($obj)) continue;

                // "@type" kann "Event" oder ["Event", ...] sein
                $type = $obj['@type'] ?? '';
                $is_event = false;
                if (is_string($type) && stripos($type, 'Event') !== false) $is_event = true;
                if (is_array($type)) {
                    foreach ($type as $t) {
                        if (is_string($t) && stripos($t, 'Event') !== false) { $is_event = true; break; }
                    }
                }
                if (!$is_event) continue;

                // Felder
                $title = $obj['name'] ?? '';
                $desc  = $obj['description'] ?? '';

                $start = $obj['startDate'] ?? '';
                if ($start) {
                    $ts = strtotime($start);
                    if ($ts) $start = date('Y-m-d H:i:s', $ts);
                }

                // Bild kann String oder Array sein
                $image = '';
                if (!empty($obj['image'])) {
                    if (is_string($obj['image'])) $image = $obj['image'];
                    elseif (is_array($obj['image'])) {
                        // häufig erstes Element der Liste
                        $image = reset($obj['image']);
                        if (is_array($image) && isset($image['url'])) $image = $image['url'];
                        if (!is_string($image)) $image = '';
                    }
                }

                // Ort
                $ort = '';
                if (!empty($obj['location'])) {
                    $loc = $obj['location'];
                    if (is_array($loc)) {
                        $name = $loc['name'] ?? '';
                        $addr = '';
                        if (!empty($loc['address'])) {
                            if (is_string($loc['address'])) {
                                $addr = $loc['address'];
                            } elseif (is_array($loc['address'])) {
                                // klassische Struktur
                                $parts = [];
                                foreach (['streetAddress','postalCode','addressLocality','addressRegion','addressCountry'] as $k) {
                                    if (!empty($loc['address'][$k])) $parts[] = $loc['address'][$k];
                                }
                                $addr = implode(' ', $parts);
                            }
                        }
                        $ort = trim($name . ($addr ? ' – ' . $addr : ''));
                    }
                }

                return [
                    'title' => (string)$title,
                    'start' => (string)$start,
                    'ort'   => (string)$ort,
                    'desc'  => (string)$desc,
                    'image' => (string)$image,
                ];
            }
        }
        return null;
    }

    /** Fallbacks: Titel/OG, erstes Bild, Textabschnitt */
    private static function extract_fallbacks(string $html): array
    {
        $out = [
            'title' => '',
            'start' => '',
            'ort'   => '',
            'desc'  => '',
            'image' => '',
        ];

        // Titel: <h1> oder og:title
        if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $html, $m)) {
            $out['title'] = wp_strip_all_tags($m[1]);
        } elseif (preg_match('~<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) {
            $out['title'] = trim($m[1]);
        }

        // Bild: og:image oder erstes <img>
        if (preg_match('~<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)) {
            $out['image'] = trim($m[1]);
        } elseif (preg_match('~<img[^>]+src=["\']([^"\']+)["\']~i', $html, $m)) {
            $out['image'] = trim($m[1]);
        }

        // Beschreibung: grob ersten größeren Textblock aus Content
        if (preg_match('~<main[^>]*>(.*?)</main>~is', $html, $m) ||
            preg_match('~<article[^>]*>(.*?)</article>~is', $html, $m) ||
            preg_match('~<div[^>]+class=["\'][^"\']*(?:content|text|beschreibung)[^"\']*["\'][^>]*>(.*?)</div>~is', $html, $m)) {
            $chunk = wp_strip_all_tags($m[1]);
            $chunk = preg_replace('/\s+/', ' ', $chunk);
            $out['desc'] = trim(mb_substr($chunk, 0, 500));
        }

        return $out;
    }

    /** Eine Detailseite parsen */
    private static function parse_detail(string $url): ?array
    {
        $html = self::fetch_url($url);
        if (!$html) return null;

        // 1) JSON-LD bevorzugt
        $ev = self::extract_event_from_jsonld($html);

        // 2) Fallbacks zusammensetzen
        $fallback = self::extract_fallbacks($html);

        $title = $ev['title'] ?? $fallback['title'];
        $start = $ev['start'] ?? $fallback['start'];
        $ort   = $ev['ort']   ?? $fallback['ort'];
        $desc  = $ev['desc']  ?? $fallback['desc'];
        $image = $ev['image'] ?? $fallback['image'];

        // Aufräumen
        $title = trim($title);
        $desc  = trim($desc);
        $ort   = trim($ort);

        // Wenn gar kein Titel: abbrechen
        if ($title === '') return null;

        return [
            'title' => $title,
            'start' => $start,
            'ort'   => $ort,
            'desc'  => $desc,
            'image' => $image,
            'url'   => $url,
        ];
    }

    /**
     * Crawl: Liste -> Detailseiten -> Ergebnis-Array
     * @return array<int, array{title:string,start:string,ort:string,desc:string,image:string,url:string}>
     */
    public static function crawl(string $list_url, int $limit = 10): array
    {
        $list_html = self::fetch_url($list_url);
        if (!$list_html) return [];

        $links = self::extract_detail_links($list_html, $list_url, $limit);
        $out   = [];

        foreach ($links as $link) {
            $ev = self::parse_detail($link);
            if ($ev) $out[] = $ev;
        }
        return $out;
    }
}

/* =====================================================
 * ADMIN-RENDERER – wird vom Menü aufgerufen
 * ===================================================== */
function kse_render_musikheide_parser_admin()
{
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

        <p>Hole Termine von
            <code><?php echo esc_html(MusikInAltenHeidekirchenParser::LIST_URL); ?></code>
            und zeige eine Vorschau.</p>

        <form method="post">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="kse_musik_list_url">Listen-URL</label></th>
                    <td>
                        <input type="url" name="kse_musik_list_url" id="kse_musik_list_url"
                               class="regular-text code" style="width: 600px"
                               value="<?php echo esc_attr($list_url); ?>" />
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

        <h2 class="title">Vorschau (<?php echo count($results); ?> gefunden)</h2>
        <table class="widefat fixed striped">
            <thead>
            <tr>
                <th style="width:28%">Titel</th>
                <th style="width:12%">Start</th>
                <th style="width:15%">Ort</th>
                <th style="width:30%">Beschreibung</th>
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
                    <td><?php echo esc_html($ev['ort']); ?></td>
                    <td><?php echo esc_html($ev['desc']); ?></td>
                    <td>
                        <?php if (!empty($ev['image'])): ?>
                            <img src="<?php echo esc_url($ev['image']); ?>"
                                 alt="" style="max-width:90px;height:auto;border:1px solid #ddd;padding:2px;background:#fff" />
                        <?php endif; ?>
                    </td>
                    <td><a href="<?php echo esc_url($ev['url']); ?>" target="_blank" rel="noopener">öffnen</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
