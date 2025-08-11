<?php
/**
 * Admin-Seite: Veranstaltungs-Export (Seevetal)
 * - Mehrfach-Suche (Komma/Space getrennt)
 * - Finden der Detail-Links (weiterlesen)
 * - Detail-Ansicht (Parser) + Import-Button (TEC)
 */

if ( ! defined('ABSPATH') ) exit;

// ----------------------------------------------------
// Helper: Pfade, Slug, Nonces
// ----------------------------------------------------
$PAGE_SLUG = 'veranstaltungs-export';
$PAGE_URL  = admin_url('admin.php?page=' . $PAGE_SLUG);
$USER_ID   = get_current_user_id();

// Transient-Key (pro User)
$TRANSIENT_KEY = 've_links_' . $USER_ID;

// ----------------------------------------------------
// Helper-Funktionen (minimal & robust)
// ----------------------------------------------------

/** Aufteilen der Suchbegriffe (Komma/Space getrennt) + säubern */
function ve_normalize_terms(string $raw) : array {
    $raw = trim($raw);
    if ($raw === '') return [];
    // Split an Komma oder whitespace
    $parts = preg_split('/[,\s]+/u', $raw);
    $terms = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && !in_array(mb_strtolower($p), array_map('mb_strtolower', $terms), true)) {
            $terms[] = $p;
        }
    }
    return $terms;
}

/** Absolute URL aus ggf. relativer Seevetal-URL bauen */
function ve_abs_url(string $href) : string {
    if (strpos($href, 'http') === 0) return $href;
    return rtrim('https://www.seevetal.de', '/') . '/' . ltrim($href, '/');
}

/** Listen-URL für Suchwort bauen */
function ve_build_listing_url(string $term) : string {
    $base = 'https://www.seevetal.de/regional/veranstaltungen/sucheplus2.html';
    $args = [
        'schnellauswahl' => 0,
        'suchwort'       => $term,
        'beginn_datum'   => '',
        'ende_datum'     => '',
        'ort'            => 0,
    ];
    return $base . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
}

/** HTML holen (WP HTTP API) */
function ve_fetch_html(string $url) : string {
    $res = wp_remote_get($url, ['timeout' => 20, 'redirection' => 5]);
    if (is_wp_error($res)) return '';
    $code = wp_remote_retrieve_response_code($res);
    if ($code !== 200) return '';
    return (string) wp_remote_retrieve_body($res);
}

/** Aus der Listen-Seite alle Detail-Links extrahieren */
function ve_extract_detail_links_from_listing_html(string $html) : array {
    $links = [];

    // 1) Regex auf typische Detail-URL-Pfade (sehr robust)
    if (preg_match_all('#href="([^"]+/regional/veranstaltungen/[^"]+?-\d{6,}-\d{5}\.html)"#i', $html, $m)) {
        foreach ($m[1] as $href) $links[] = ve_abs_url($href);
    }
    if (preg_match_all('#href="/regional/veranstaltungen/[^"]+?-\d{6,}-\d{5}\.html"#i', $html, $m2)) {
        foreach ($m2[0] as $attr) {
            // href="..."; extrahieren
            if (preg_match('#href="([^"]+)"#i', $attr, $one)) $links[] = ve_abs_url($one[1]);
        }
    }

    // 2) DOM/XPath Fallback auf Anker mit Text „weiterlesen“
    if (empty($links)) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);
        $nodes = $xp->query("//a[contains(translate(normalize-space(string(.)),'WEITERLESEN','weiterlesen'),'weiterlesen')]/@href");
        if ($nodes && $nodes->length) {
            foreach ($nodes as $n) $links[] = ve_abs_url($n->nodeValue);
        }
    }

    // Bereinigen / eindeutige
    $links = array_values(array_unique($links));
    // gelegentlich enthalten Such-Links Anker/Tracking → säubern
    $links = array_map(function($u){
        $u = preg_replace('#(\?|\&)pk_campaign=[^&]+#i', '', $u);
        $u = preg_replace('#(\?|\&)pk_kwd=[^&]+#i', '', $u);
        return $u;
    }, $links);

    return $links;
}

/** Ergebnisse (pro User) speichern/holen */
function ve_set_last_links(array $map, string $key) : void {
    set_transient($key, $map, HOUR_IN_SECONDS);
}
function ve_get_last_links(string $key) : array {
    $v = get_transient($key);
    return is_array($v) ? $v : [];
}

/** Versuch, Parser zu laden und Detail-JSON zu bauen */
function ve_try_parse_detail_to_json(string $detail_url) {
    $file = plugin_dir_path(__FILE__) . '../crawler/SeevetalParser.php';
    if (file_exists($file)) {
        require_once $file;
        if (class_exists('SeevetalParser')) {
            $parser = new SeevetalParser();
            if (method_exists($parser, 'parse_detail_to_json')) {
                return $parser->parse_detail_to_json($detail_url);
            }
            // Fallback: ältere Methode
            if (method_exists($parser, 'parse_detail_page')) {
                return $parser->parse_detail_page($detail_url);
            }
        }
    }
    return null; // Parser nicht vorhanden oder keine passende Methode
}

/** Ascii-Tabelle aus verschachteltem Array rendern */
function ve_render_kv_table($data) {
    if (!is_array($data)) {
        echo '<p><em>Keine Daten</em></p>';
        return;
    }
    echo '<table class="widefat striped" style="margin-top:10px">';
    echo '<thead><tr><th style="width:240px">Feld</th><th>Wert</th></tr></thead><tbody>';
    $render = function($arr, $prefix='') use (&$render) {
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? $k : $prefix . '.' . $k;
            echo '<tr>';
            echo '<td><code>' . esc_html($key) . '</code></td>';
            echo '<td>';
            if (is_array($v)) {
                echo '<details><summary>Objekt/Array</summary>';
                echo '<div style="margin-left:10px">';
                $render($v, $key);
                echo '</div></details>';
            } else {
                if (is_string($v) && preg_match('#^https?://#i', $v)) {
                    echo '<a href="' . esc_url($v) . '" target="_blank" rel="noopener">' . esc_html($v) . '</a>';
                } else {
                    echo nl2br(esc_html((string)$v));
                }
            }
            echo '</td></tr>';
        }
    };
    $render($data);
    echo '</tbody></table>';
}

/** TEC-Import-URL (admin-post) bauen */
function ve_build_import_url(string $detail_url) : string {
    return wp_nonce_url(
        admin_url('admin-post.php?action=ve_import_tec&url=' . rawurlencode($detail_url)),
        've_import_tec'
    );
}

// ----------------------------------------------------
// Action-Handling (dieselbe Seite)
// ----------------------------------------------------
$action = isset($_POST['ve_action']) ? sanitize_key($_POST['ve_action']) : (isset($_GET['ve_action']) ? sanitize_key($_GET['ve_action']) : '');

$notice = '';
$errors = [];

// A) Suche ausführen
if ($action === 'search' && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 've_search')) {
    if (!current_user_can('manage_options')) wp_die('Kein Zugriff');
    $terms_raw = isset($_POST['ve_terms']) ? sanitize_text_field(wp_unslash($_POST['ve_terms'])) : '';
    $terms = ve_normalize_terms($terms_raw);

    if (empty($terms)) {
        $errors[] = 'Bitte mindestens ein Suchwort eingeben.';
    } else {
        $result_map = [];
        foreach ($terms as $t) {
            $list_url = ve_build_listing_url($t);
            $html     = ve_fetch_html($list_url);
            $links    = $html ? ve_extract_detail_links_from_listing_html($html) : [];
            $result_map[$t] = [
                'listing_url' => $list_url,
                'links'       => $links,
                'count'       => count($links),
            ];
        }
        ve_set_last_links($result_map, $TRANSIENT_KEY);
        $notice = 'Suche abgeschlossen.';
    }
}

// B) Letzte Ergebnisse löschen
if ($action === 'clear' && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 've_clear')) {
    if (!current_user_can('manage_options')) wp_die('Kein Zugriff');
    delete_transient($TRANSIENT_KEY);
    $notice = 'Zwischenspeicher gelöscht.';
}

// C) Detail anzeigen
$show_data = null;
$show_url  = '';
if ($action === 'show' && isset($_GET['url'])) {
    $show_url = esc_url_raw(wp_unslash($_GET['url']));
    // Optionaler Nonce (nicht zwingend erforderlich fürs Anzeigen)
    $parsed = ve_try_parse_detail_to_json($show_url);
    if ($parsed === null) {
        $errors[] = 'Parser nicht verfügbar oder Methode fehlt. Bitte `crawler/SeevetalParser.php` mit `parse_detail_to_json($url)` bereitstellen.';
    } else {
        $show_data = $parsed;
    }
}

// ----------------------------------------------------
// Ausgabe
// ----------------------------------------------------
?>
<div class="wrap">
    <h1>Event Export – Seevetal</h1>

    <?php if ($notice): ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="notice notice-error">
            <ul style="margin-left:1em; list-style:disc;">
                <?php foreach ($errors as $e): ?>
                    <li><?php echo esc_html($e); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url($PAGE_URL); ?>" style="margin-top:10px;">
        <?php wp_nonce_field('ve_search'); ?>
        <input type="hidden" name="ve_action" value="search">
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ve_terms">Suchwörter</label></th>
                <td>
                    <input type="text" class="regular-text" id="ve_terms" name="ve_terms"
                           placeholder="z. B. Musik, Kultur, Theater"
                           value="<?php echo isset($_POST['ve_terms']) ? esc_attr($_POST['ve_terms']) : ''; ?>">
                    <p class="description">Mehrere Begriffe mit <strong>Komma</strong> oder <strong>Leerzeichen</strong> trennen.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Links suchen'); ?>
    </form>

    <form method="post" action="<?php echo esc_url($PAGE_URL); ?>" style="margin-top:0;">
        <?php wp_nonce_field('ve_clear'); ?>
        <input type="hidden" name="ve_action" value="clear">
        <?php submit_button('Zwischenspeicher leeren', 'secondary'); ?>
    </form>

    <hr>

    <?php
    // Letzte Ergebnisse laden
    $map = ve_get_last_links($TRANSIENT_KEY);
    if (!empty($map)) :
    ?>
        <h2>Gefundene Detail-Links</h2>
        <?php foreach ($map as $term => $info) : ?>
            <h3 style="margin-top:20px;">
                Suchwort: <code><?php echo esc_html($term); ?></code>
                <span class="description"> – <?php echo intval($info['count']); ?> Treffer</span>
            </h3>
            <p>
                Listen-Seite: <a href="<?php echo esc_url($info['listing_url']); ?>" target="_blank" rel="noopener">
                    <?php echo esc_html($info['listing_url']); ?></a>
            </p>
            <?php if (!empty($info['links'])): ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:50px;">#</th>
                            <th>Detail-URL</th>
                            <th style="width:360px;">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($info['links'] as $i => $link): ?>
                        <?php
                        $show_link   = add_query_arg([
                            'page'      => $PAGE_SLUG,
                            've_action' => 'show',
                            'url'       => rawurlencode($link),
                        ], admin_url('admin.php'));

                        $import_link = ve_build_import_url($link);
                        ?>
                        <tr>
                            <td><?php echo intval($i + 1); ?></td>
                            <td>
                                <a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener"><?php echo esc_html($link); ?></a>
                            </td>
                            <td>
                                <a class="button" href="<?php echo esc_url($show_link); ?>">Details anzeigen</a>
                                <a class="button" href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener">Externe Seite öffnen</a>
                                <a class="button button-primary" href="<?php echo esc_url($import_link); ?>">
                                    In The Events Calendar importieren
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><em>Keine Detail-Links gefunden.</em></p>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php else: ?>
        <p><em>Noch keine Ergebnisse. Bitte oben Suchwörter eingeben und „Links suchen“ starten.</em></p>
    <?php endif; ?>

    <?php if ($show_data !== null): ?>
        <hr>
        <h2>Parsed Details</h2>
        <p>
            Quelle:&nbsp;
            <a href="<?php echo esc_url($show_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($show_url); ?></a>
        </p>

        <?php
        // Falls Parser schon unser Schema liefert, versuchen wir hilfreiche Highlights:
        $image_url = '';
        if (is_array($show_data)) {
            // häufiges Schema: ['event']['image_url'] bzw. og:image
            if (!empty($show_data['event']['image_url'])) {
                $image_url = $show_data['event']['image_url'];
            } elseif (!empty($show_data['og_image'])) {
                $image_url = $show_data['og_image'];
            }
        }

        if ($image_url) {
            echo '<div style="margin:10px 0"><img src="' . esc_url($image_url) . '" alt="" style="max-width:300px;height:auto;border:1px solid #ccd0d4;padding:4px;background:#fff"></div>';
        }

        ve_render_kv_table($show_data);

        $import_link = ve_build_import_url($show_url);
        ?>
        <p class="submit">
            <a class="button" href="<?php echo esc_url($show_url); ?>" target="_blank" rel="noopener">Externe Seite öffnen</a>
            <a class="button button-primary" href="<?php echo esc_url($import_link); ?>">In The Events Calendar importieren</a>
            <a class="button" href="<?php echo esc_url($PAGE_URL); ?>">Zurück zur Übersicht</a>
        </p>
    <?php endif; ?>
</div>
