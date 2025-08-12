<?php
/**
 * Admin-Seite + Parser für "Musik in alten Heidekirchen"
 *
 * Funktionsweise:
 * - Listenseite https://musik-in-alten-heidekirchen.wir-e.de/termine wird geladen
 * - Alle Detail-Links (/termine/<uuid>) werden gesammelt
 * - Jede Detailseite wird geparst (Titel, Datum/Zeit, Bild, Beschreibung, ggf. Ort)
 * - Ergebnisse werden in der Admin-Seite als Tabelle angezeigt
 * - Optional: Auswahl importieren -> ruft (falls vorhanden) ve_import_events_from_array($events) aus veranstaltungs-export.php
 *
 * Leicht anpassbar und ohne zusätzliche Libraries (nutzt DOMDocument + XPath).
 */

if (!defined('ABSPATH')) { exit; }

const MIH_BASE          = 'https://musik-in-alten-heidekirchen.wir-e.de';
const MIH_LIST_PATH     = '/termine';
const MIH_UA            = 'Kulturstiftung-Seevetal-Parser/1.0 (+WordPress; +TheEventsCalendar)';
const MIH_SOURCE_SLUG   = 'musik-in-alten-heidekirchen';
const MIH_TZ            = 'Europe/Berlin';

add_action('admin_menu', function () {
    // Falls du den Menüeintrag lieber zentral in menu.php pflegst, nutze die Snippets weiter unten.
    add_menu_page(
        'Musik in alten Heidekirchen',
        'MiAH Parser',
        'manage_options',
        'mih-parser',
        'mih_render_admin_page',
        'dashicons-album',
        61
    );
});

/** =============== Admin-Page =============== */
function mih_render_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    $action = isset($_POST['mih_action']) ? sanitize_text_field($_POST['mih_action']) : '';
    $events = [];

    if ($action === 'crawl' && check_admin_referer('mih_crawl')) {
        $events = mih_crawl_all();
    }

    if ($action === 'import' && check_admin_referer('mih_import')) {
        $selected = isset($_POST['mih_selected']) && is_array($_POST['mih_selected']) ? array_map('sanitize_text_field', $_POST['mih_selected']) : [];
        $payload  = isset($_POST['mih_payload']) ? json_decode(stripslashes($_POST['mih_payload']), true) : [];
        $to_import = [];

        foreach ($payload as $idx => $ev) {
            if (in_array((string)$idx, $selected, true)) {
                $to_import[] = $ev;
            }
        }

        $imported = 0;
        if (!empty($to_import)) {
            // 1) Wenn euer Exporter eine Funktion bereitstellt, nutzen wir sie:
            if (function_exists('ve_import_events_from_array')) {
                $imported = (int) ve_import_events_from_array($to_import, MIH_SOURCE_SLUG);
            } elseif (file_exists(plugin_dir_path(__FILE__) . 'veranstaltungs-export.php')) {
                // 2) Versuche, den Exporter einzubinden (optional)
                require_once plugin_dir_path(__FILE__) . 'veranstaltungs-export.php';
                if (function_exists('ve_import_events_from_array')) {
                    $imported = (int) ve_import_events_from_array($to_import, MIH_SOURCE_SLUG);
                }
            }
        }

        echo '<div class="notice notice-success"><p>Import abgeschlossen. Anzahl: ' . esc_html($imported) . '</p></div>';
        // Nach dem Import könnten wir die Liste erneut anzeigen:
        $events = mih_crawl_all();
    }

    echo '<div class="wrap"><h1>Musik in alten Heidekirchen – Parser</h1>';

    echo '<form method="post" style="margin: 1rem 0;">';
    wp_nonce_field('mih_crawl');
    echo '<input type="hidden" name="mih_action" value="crawl" />';
    submit_button('Termine laden', 'primary', 'submit', false);
    echo '</form>';

    if (!empty($events)) {
        // JSON Payload zum Mitgeben beim Import
        $json = wp_json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        echo '<form method="post">';
        wp_nonce_field('mih_import');
        echo '<input type="hidden" name="mih_action" value="import" />';
        echo '<input type="hidden" name="mih_payload" value="' . esc_attr($json) . '" />';

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:40px">#</th>';
        echo '<th>Titel</th><th>Start</th><th>Ort</th><th>Bild</th><th>Quelle</th><th>Tags (vorgeschlagen)</th>';
        echo '</tr></thead><tbody>';

        foreach ($events as $i => $ev) {
            echo '<tr>';
            echo '<td><label><input type="checkbox" name="mih_selected[]" value="' . esc_attr($i) . '" checked /> ' . (int)$i . '</label></td>';
            echo '<td><strong>' . esc_html($ev['title']) . '</strong><br><small>' . esc_html(wp_trim_words(wp_strip_all_tags($ev['description_html']), 30)) . '</small></td>';
            echo '<td>' . esc_html($ev['start']) . (isset($ev['end']) ? '<br>&rarr; ' . esc_html($ev['end']) : '') . '</td>';
            echo '<td>' . esc_html(trim(($ev['location_name'] ?? '') . ' ' . ($ev['location_city'] ?? ''))) . '</td>';
            echo '<td>' . (!empty($ev['image']) ? '<img src="' . esc_url($ev['image']) . '" alt="" style="max-width:120px; height:auto;" />' : '') . '</td>';
            echo '<td><a href="' . esc_url($ev['source_url']) . '" target="_blank" rel="noopener">Detail</a></td>';
            echo '<td>' . esc_html(implode(', ', $ev['tags'] ?? [])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<p style="margin-top:1rem">';
        submit_button('Ausgewählte importieren', 'primary', 'submit', false);
        echo ' <a class="button button-secondary" href="data:application/json;charset=utf-8,' . rawurlencode($json) . '" download="mih-events.json">JSON herunterladen</a>';
        echo '</p>';

        echo '</form>';
    }

    echo '</div>';
}

/** =============== Crawl =============== */

function mih_crawl_all(): array {
    $list_html = mih_http_get(MIH_BASE . MIH_LIST_PATH);
    if (!$list_html) return [];

    $list_urls = mih_extract_detail_urls($list_html);
    $events = [];
    foreach ($list_urls as $url) {
        $detail_html = mih_http_get($url);
        if (!$detail_html) continue;
        $ev = mih_parse_detail($detail_html, $url);
        if (!empty($ev)) $events[] = $ev;
        // Optional: kleine Pause, falls die Seite empfindlich ist.
        usleep(150000);
    }
    return $events;
}

function mih_http_get(string $url): string {
    $res = wp_remote_get($url, [
        'timeout'     => 15,
        'user-agent'  => MIH_UA,
        'redirection' => 5,
        'headers'     => [
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
        ],
    ]);

    if (is_wp_error($res)) return '';
    $code = wp_remote_retrieve_response_code($res);
    if ($code !== 200) return '';
    $body = wp_remote_retrieve_body($res);
    return is_string($body) ? $body : '';
}

function mih_dom_xpath(string $html): ?DOMXPath {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    if (!$loaded) return null;
    return new DOMXPath($doc);
}

function mih_extract_detail_urls(string $html): array {
    $xp = mih_dom_xpath($html);
    if (!$xp) return [];

    $urls = [];

    // Strategie:
    //  - wir-e.de/termine listet Karten mit Links auf /termine/<uuid>
    //  - wir nehmen alle <a> mit href, die "/termine/" enthalten, deduplizieren und normalisieren
    $nodes = $xp->query("//a[@href]");
    foreach ($nodes as $a) {
        $href = trim($a->getAttribute('href'));
        if ($href === '') continue;

        // Nur Detailseiten
        if (preg_match('#/termine/[a-f0-9\-]{10,}#i', $href)) {
            $urls[] = mih_abs_url($href);
        }
        // Manche Links sind relativ (../termine/xyz)
        if (strpos($href, '/termine/') === 0) {
            $urls[] = mih_abs_url($href);
        }
    }

    $urls = array_values(array_unique($urls));
    return $urls;
}

function mih_abs_url(string $href): string {
    if (preg_match('#^https?://#i', $href)) return $href;
    return rtrim(MIH_BASE, '/') . '/' . ltrim($href, '/');
}

function mih_parse_detail(string $html, string $url): array {
    $xp = mih_dom_xpath($html);
    if (!$xp) return [];

    $title = mih_first_text($xp, "//h2") ?: mih_meta($xp, 'og:title') ?: 'Ohne Titel';

    // Datum/Zeit: "17.08.2025 / 17:00" steht in <h4 class="date">
    $date_raw = mih_first_text($xp, "//h4[contains(@class,'date')]");
    $start = '';
    $end   = '';

    if ($date_raw && preg_match('#(\d{2})\.(\d{2})\.(\d{4})\s*/\s*(\d{2}):(\d{2})#', $date_raw, $m)) {
        $d = "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:00";
        try {
            $dt = new DateTime($d, new DateTimeZone(MIH_TZ));
            $start = $dt->format('Y-m-d H:i:s');
            // Heuristik: 2 Stunden Dauer
            $dt->modify('+2 hours');
            $end = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {}
    }

    // Bild: zuerst og:image, dann Bild innerhalb .teaser-image
    $image = mih_meta($xp, 'og:image');
    if (!$image) {
        $img = $xp->query("//figure[contains(@class,'teaser-image')]//img[@src]");
        if ($img && $img->length) {
            /** @var DOMElement $n */
            $n = $img->item(0);
            $image = mih_abs_url($n->getAttribute('src'));
        }
    }

    // Beschreibung: Text aus .content-elements .text-element-body
    $desc_html = mih_first_html($xp, "//div[contains(@class,'content-elements')]//div[contains(@class,'text-element-body')]");
    if (!$desc_html) {
        // Fallback: irgendein Content-Element
        $desc_html = mih_first_html($xp, "//div[contains(@class,'content-elements')]");
    }

    // Ort (sofern vorhanden): Wir versuchen typische Stellen / Labels
    $location_name = '';
    $location_city = '';
    // Manche Seiten haben Adresse in einem Textblock -> einfache Heuristik
    $full_text = strip_tags($desc_html ?? '');
    if (preg_match('#(Kirche|Kapelle|Dom|Kloster|Marktplatz|Open Air|Kirchgemeinde)[^\n\r<]{0,120}#ui', $full_text, $m)) {
        $location_name = trim($m[0]);
    }

    // Schlagwortvorschläge (für Tag-Matching)
    $tags = mih_suggest_tags($title . ' ' . $full_text);

    return [
        'source'          => 'Musik in alten Heidekirchen',
        'source_slug'     => MIH_SOURCE_SLUG,
        'source_url'      => $url,
        'title'           => $title,
        'start'           => $start,
        'end'             => $end,
        'timezone'        => MIH_TZ,
        'location_name'   => $location_name,
        'location_city'   => $location_city,
        'image'           => $image,
        'description_html'=> $desc_html ?: '',
        'tags'            => $tags,
        // Für Deduplizierung:
        'fingerprint'     => md5($title . '|' . $start . '|' . $location_name),
    ];
}

function mih_first_text(DOMXPath $xp, string $xpath): string {
    $n = $xp->query($xpath);
    if ($n && $n->length) {
        return trim(preg_replace('/\s+/', ' ', $n->item(0)->textContent ?? ''));
    }
    return '';
}

function mih_first_html(DOMXPath $xp, string $xpath): string {
    $n = $xp->query($xpath);
    if ($n && $n->length) {
        $node = $n->item(0);
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return $html;
    }
    return '';
}

function mih_meta(DOMXPath $xp, string $property): string {
    $n = $xp->query("//meta[@property='$property' or @name='$property']/@content");
    if ($n && $n->length) {
        return trim($n->item(0)->nodeValue ?? '');
    }
    return '';
}

function mih_suggest_tags(string $text): array {
    $text = mb_strtolower($text, 'UTF-8');
    $suggestions = [];

    $map = [
        'open air'   => 'Open Air',
        'open-air'   => 'Open Air',
        'orgel'      => 'Orgel',
        'chor'       => 'Chor',
        'ensemble'   => 'Ensemble',
        'kammer'     => 'Kammermusik',
        'barock'     => 'Barock',
        'jazz'       => 'Jazz',
        'weihnacht'  => 'Weihnachten',
        'kinder'     => 'Kinder',
        'lesung'     => 'Lesung',
        'vortrag'    => 'Vortrag',
        'brass'      => 'Blechbläser',
        'embrassment'=> 'Blechbläser',
        'emBRASSment'=> 'Blechbläser', // Helferlein
        'festival'   => 'Festival',
        'sommer'     => 'Sommer',
        'weihnacht'  => 'Weihnachten',
        'advent'     => 'Advent',
        'gospel'     => 'Gospel',
        'kantate'    => 'Kantate',
        'konzert'    => 'Konzert',
        'musik'      => 'Musik',
    ];

    foreach ($map as $needle => $tag) {
        if (mb_strpos($text, mb_strtolower($needle, 'UTF-8')) !== false) {
            $suggestions[] = $tag;
        }
    }

    // Basis-Tags immer vorschlagen:
    if (!in_array('Musik', $suggestions, true))   $suggestions[] = 'Musik';
    if (!in_array('Konzert', $suggestions, true)) $suggestions[] = 'Konzert';

    $suggestions = array_values(array_unique($suggestions));
    return $suggestions;
}
