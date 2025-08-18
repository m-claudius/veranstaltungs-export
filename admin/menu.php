<?php
if (!defined('ABSPATH')) exit;

/**
 * Menü „Event Import“ + Quell-spezifische Seiten
 * Benötigt:
 *  - KSE_PLUGIN_DIR Konstante in der Hauptdatei
 *  - includes/tec_import.php (mit kse_tec_upsert_event und Schutzlogik)
 *  - crawler/MusikInAltenHeidekirchenParser.php
 *  - crawler/SeevetalParser.php
 *  - crawler/EmporeBuchholzParser.php
 */

add_action('admin_menu', function () {
    // Hauptmenü
    add_menu_page(
        'Event Import',
        'Event Import',
        'manage_options',
        'kse-import',
        'kse_render_dashboard',
        'dashicons-calendar-alt',
        60
    );

    // Quellen
    add_submenu_page('kse-import', 'Musik in alten Heidekirchen', 'Musik in alten Heidekirchen', 'manage_options', 'kse-miah', 'kse_render_miah_admin');
    add_submenu_page('kse-import', 'Gemeinde Seevetal', 'Gemeinde Seevetal', 'manage_options', 'kse-seevetal', 'kse_render_seevetal_admin');
    add_submenu_page('kse-import', 'Empore Buchholz', 'Empore Buchholz', 'manage_options', 'kse-empore', 'kse_render_empore_admin');

    // Weitere Seiten (Stub, damit sichtbar)
    add_submenu_page('kse-import', 'Einstellungen', 'Einstellungen', 'manage_options', 'kse-settings', 'kse_render_settings_stub');
    add_submenu_page('kse-import', 'Statistik', 'Statistik', 'manage_options', 'kse-stats', 'kse_render_stats_stub');
});

// --- Includes robust einbinden ---
if (defined('KSE_PLUGIN_DIR')) {
    $inc = KSE_PLUGIN_DIR . 'includes/tec_import.php';
    if (file_exists($inc)) require_once $inc;

    $p1 = KSE_PLUGIN_DIR . 'crawler/MusikInAltenHeidekirchenParser.php';
    if (file_exists($p1)) require_once $p1;

    $p2 = KSE_PLUGIN_DIR . 'crawler/SeevetalParser.php';
    if (file_exists($p2)) require_once $p2;

    $p3 = KSE_PLUGIN_DIR . 'crawler/EmporeBuchholzParser.php';
    if (file_exists($p3)) require_once $p3;
}

// ---------- Dashboard (kleine Info) ----------
function kse_render_dashboard() {
    echo '<div class="wrap"><h1>Event Import</h1><p>Wähle links eine Quelle aus, um Termine zu crawlen und zu importieren.</p></div>';
}

// ---------- Helfer: sichere GET/POST ----------
function kse_post($key, $default = null) { return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default; }
function kse_get($key, $default = null) { return isset($_GET[$key]) ? wp_unslash($_GET[$key]) : $default; }

// ---------- Helfer: Event-Tabelle rendern ----------
function kse_render_event_table(array $events, string $source_slug, string $submit_label = 'Ausgewählte importieren') {
    $events = array_values(array_filter($events, 'is_array'));
    echo '<form method="post">';
    wp_nonce_field('kse_import_'.$source_slug, 'kse_nonce');
    echo '<input type="hidden" name="kse_action" value="import_selected">';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th style="width:24px"><input type="checkbox" onclick="jQuery(\'.kse_rowchk\').prop(\'checked\', this.checked)"></th>';
    echo '<th>Titel</th><th>Start</th><th>Ort</th><th>Quelle</th><th>Bild</th>';
    echo '</tr></thead><tbody>';

    foreach ($events as $ev) {
        $title = trim($ev['title'] ?? '');
        $start = trim($ev['start'] ?? ($ev['datetime'] ?? ''));
        $loc   = trim($ev['location'] ?? '');
        $src   = esc_url($ev['source_url'] ?? ($ev['source'] ?? ''));
        $img   = esc_url($ev['image'] ?? '');
        $key   = substr(md5(($src ?: $title.$start).maybe_serialize($ev)), 0, 12);

        // Hidden Payload (Base64 JSON)
        $packed = base64_encode(wp_json_encode($ev));
        echo '<tr>';
        echo '<td><input type="checkbox" class="kse_rowchk" name="selected[]" value="'.esc_attr($key).'"></td>';
        echo '<td>'.esc_html($title ?: '(ohne Titel)').'</td>';
        echo '<td>'.esc_html($start ?: '').'</td>';
        echo '<td>'.esc_html($loc ?: '').'</td>';
        echo '<td>'.($src ? '<a href="'.$src.'" target="_blank" rel="noopener">öffnen</a>' : '').'</td>';
        echo '<td>'.($img ? '<img src="'.$img.'" style="max-width:80px;height:auto;border:1px solid #ccc" />' : '').'</td>';
        echo '</tr>';
        echo '<input type="hidden" name="event_json['.esc_attr($key).']" value="'.esc_attr($packed).'">';
    }

    echo '</tbody></table>';
    echo '<p style="margin-top:12px"><label><input type="checkbox" name="dry_run" value="1"> Dry-Run (nur anzeigen, nichts schreiben)</label></p>';
    echo '<p><button type="submit" class="button button-primary">'.$submit_label.'</button></p>';
    echo '</form>';
}

// ---------- Gemeinsamer Import-Handler ----------
function kse_handle_import_selected(string $source_slug, string $source_category) {
    if (!current_user_can('manage_options')) return;
    check_admin_referer('kse_import_'.$source_slug, 'kse_nonce');

    $selected = (array) kse_post('selected', []);
    $selected = array_values(array_unique(array_map('sanitize_text_field', $selected)));
    $all_ev   = (array) kse_post('event_json', []);

    $dry = !empty($_POST['dry_run']);
    $done = 0;

    if (!$selected) {
        echo '<div class="notice notice-warning"><p>Keine Auswahl getroffen.</p></div>';
        return;
    }

    if (!function_exists('kse_tec_upsert_event')) {
        echo '<div class="notice notice-error"><p>Importer fehlt: includes/tec_import.php nicht geladen.</p></div>';
        return;
    }

    echo '<ul style="list-style:disc;margin-left:20px">';
    foreach ($selected as $key) {
        if (empty($all_ev[$key])) continue;
        $ev = json_decode(base64_decode($all_ev[$key]), true);
        if (!is_array($ev)) continue;

        $src = esc_url_raw($ev['source_url'] ?? ($ev['source'] ?? ''));
        $desc = (string)($ev['description'] ?? '');
        if ($src) {
            // Vorgabe: am Ende eine Leerzeile + „Quelle: (C) <URL>“
            $desc = rtrim($desc)."\n\nQuelle: (C) ".$src;
        }
        $payload = [
            'title'       => (string)($ev['title'] ?? ''),
            'description' => $desc,
            'start'       => (string)($ev['start'] ?? ($ev['datetime'] ?? '')),
            'location'    => (string)($ev['location'] ?? ''),
            'image'       => (string)($ev['image'] ?? ''),
            'source_url'  => $src,
        ];

        if ($dry) {
            echo '<li><strong>'.esc_html($payload['title'] ?: '(ohne Titel)').'</strong> — Dry-Run → <a href="'.esc_url($src).'" target="_blank" rel="noopener">Quelle</a></li>';
            continue;
        }

        $result = kse_tec_upsert_event($payload, [
            'default_duration_minutes' => 120,
            'source_slug'              => $source_slug,
            'source_category'          => $source_category,
        ]);

        $tec_link = !empty($result['permalink']) ? $result['permalink'] : '';
        $post_id  = !empty($result['post_id']) ? (int)$result['post_id'] : 0;
        $action   = $result['action'] ?? '';
        $label    = ($action === 'skipped_own' || $action === 'skipped_own_protected') ? 'Übersprungen (eigene)'
                   : (str_starts_with($action, 'skipped_foreign') ? 'Übersprungen (andere Quelle)' : strtoupper($action));

        printf(
            '<li><strong>%s</strong> — <a href="%s" target="_blank" rel="noopener">Quelle</a> → %s <em>(%s)</em></li>',
            esc_html($payload['title'] ?: '(ohne Titel)'),
            esc_url($src),
            ($post_id && $tec_link) ? '<a href="'.esc_url($tec_link).'" target="_blank" rel="noopener">TEC #'.$post_id.'</a>' : 'TEC (kein Link)',
            esc_html($label)
        );
        $done++;
    }
    echo '</ul>';

    if (!$dry) {
        echo '<div class="notice notice-success"><p>Fertig. '.$done.' Einträge verarbeitet.</p></div>';
    }
}

// ---------- Seite: Musik in alten Heidekirchen ----------
function kse_render_miah_admin() {
    echo '<div class="wrap"><h1>Musik in alten Heidekirchen – Parser</h1>';
    if (!class_exists('MusikInAltenHeidekirchenParser')) {
        echo '<div class="notice notice-error"><p>Der Parser ist nicht geladen. Prüfe <code>crawler/MusikInAltenHeidekirchenParser.php</code>.</p></div></div>';
        return;
    }

    // POST: Import
    if (!empty($_POST['kse_action']) && $_POST['kse_action']==='import_selected') {
        kse_handle_import_selected('miah', 'Musik in alten Heidekirchen');
        echo '</div>'; return;
    }

    // Crawl & zeigen
    $parser = new MusikInAltenHeidekirchenParser();
    $events = $parser->crawl(); // erwartet Array von Events
    if (!is_array($events) || !$events) {
        echo '<div class="notice notice-warning"><p>Keine Veranstaltungen gefunden.</p></div></div>';
        return;
    }

    kse_render_event_table($events, 'miah', 'Ausgewählte importieren');
    echo '</div>';
}

// ---------- Seite: Gemeinde Seevetal ----------
function kse_render_seevetal_admin() {
    echo '<div class="wrap"><h1>Gemeinde Seevetal – Parser</h1>';
    if (!class_exists('SeevetalParser')) {
        echo '<div class="notice notice-error"><p>Der Parser ist nicht geladen. Prüfe <code>crawler/SeevetalParser.php</code>.</p></div></div>';
        return;
    }

    // POST: Import
    if (!empty($_POST['kse_action']) && $_POST['kse_action']==='import_selected') {
        kse_handle_import_selected('seevetal', 'Gemeinde Seevetal');
        echo '</div>'; return;
    }

    // Optional Suchbegriffe (Whitelist)
    $terms = isset($_GET['terms']) ? sanitize_text_field($_GET['terms']) : '';
    echo '<form method="get" style="margin:10px 0">';
    echo '<input type="hidden" name="page" value="kse-seevetal">';
    echo '<label>Suchworte (Komma-getrennt): <input type="text" name="terms" value="'.esc_attr($terms).'" style="min-width:320px"></label> ';
    echo '<button class="button">Neu laden</button>';
    echo '</form>';

    $parser = new SeevetalParser();
    $events = $parser->crawl(['terms'=>$terms]); // deine Implementierung nutzt terms ggf.
    if (!is_array($events) || !$events) {
        echo '<div class="notice notice-warning"><p>Keine Veranstaltungen gefunden.</p></div></div>';
        return;
    }

    kse_render_event_table($events, 'seevetal', 'Ausgewählte importieren');
    echo '</div>';
}

// ---------- Seite: Empore Buchholz ----------
function kse_render_empore_admin() {
    echo '<div class="wrap"><h1>Empore Buchholz – Parser</h1>';
    if (!class_exists('EmporeBuchholzParser')) {
        echo '<div class="notice notice-error"><p>Der Parser ist nicht geladen. Prüfe <code>crawler/EmporeBuchholzParser.php</code>.</p></div></div>';
        return;
    }

    // POST: Import
    if (!empty($_POST['kse_action']) && $_POST['kse_action']==='import_selected') {
        kse_handle_import_selected('empore', 'Empore Buchholz');
        echo '</div>'; return;
    }

    $parser = new EmporeBuchholzParser();
    $events = $parser->crawl(); // erste Version
    if (!is_array($events) || !$events) {
        echo '<div class="notice notice-warning"><p>Keine Veranstaltungen gefunden.</p></div></div>';
        return;
    }

    kse_render_event_table($events, 'empore', 'Ausgewählte importieren');
    echo '</div>';
}

// ---------- Stub-Seiten ----------
function kse_render_settings_stub() {
    echo '<div class="wrap"><h1>Einstellungen</h1><p>Mehr in Kürze.</p></div>';
}
function kse_render_stats_stub() {
    echo '<div class="wrap"><h1>Statistik</h1><p>Mehr in Kürze.</p></div>';
}
