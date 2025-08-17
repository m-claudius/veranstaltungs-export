<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin-Menü „Event Import“ + Unterseiten
 * - Musik in alten Heidekirchen – Parser
 * - Gemeinde Seevetal – Parser
 * - Einstellungen
 * - Statistik
 */

add_action('admin_menu', function () {

    add_menu_page(
        'Event Import',
        'Event Import',
        'read',
        'kse-event-import',
        'kse_render_event_import_landing',
        'dashicons-calendar-alt'
    );

    add_submenu_page(
        'kse-event-import',
        'Musik in alten Heidekirchen – Parser',
        'Musik in alten Heidekirchen',
        'read',
        'kse-miah',
        'kse_render_miah_admin'
    );

    add_submenu_page(
        'kse-event-import',
        'Gemeinde Seevetal – Parser',
        'Gemeinde Seevetal',
        'read',
        'kse-seevetal',
        'kse_render_seevetal_admin'
    );

    add_submenu_page(
        'kse-event-import',
        'Einstellungen',
        'Einstellungen',
        'manage_options',
        'kse-settings',
        'kse_render_settings_admin' // in admin/settings.php
    );

    add_submenu_page(
        'kse-event-import',
        'Statistik',
        'Statistik',
        'manage_options',
        'kse-stats',
        'kse_render_stats_admin' // in includes/stats.php
    );

    add_submenu_page(
        'kse-event-import',           // parent slug (bei dir ggf. anpassen)
        'Dubletten',
        'Dubletten',
        'edit_posts',
        'kse-dedupe',
        'kse_render_dedupe_admin'
        );

});

/* ---------------- Landing ---------------- */

function kse_render_event_import_landing() {
    echo '<div class="wrap"><h1>Event Import</h1>';
    if (!empty($_GET['kse_msg'])) {
        echo '<div class="notice notice-success"><p>'.esc_html(wp_unslash($_GET['kse_msg'])).'</p></div>';
    }
    echo '<p>Wähle links eine Quelle:</p>
        <ul style="list-style:disc;margin-left:20px">
          <li><a href="'.esc_url(admin_url('admin.php?page=kse-miah')).'">Musik in alten Heidekirchen</a></li>
          <li><a href="'.esc_url(admin_url('admin.php?page=kse-seevetal')).'">Gemeinde Seevetal</a></li>
          <li><a href="'.esc_url(admin_url('admin.php?page=kse-settings')).'">Einstellungen</a></li>
          <li><a href="'.esc_url(admin_url('admin.php?page=kse-stats')).'">Statistik</a></li>
        </ul>
    </div>';
}

function kse_render_dedupe_admin(){
    echo '<div class="wrap"><h1>Dubletten zusammenführen</h1>';
    $groups = kse_find_duplicates_groups(180);
    if (!$groups) { echo '<p>Keine Dubletten gefunden.</p></div>'; return; }

    echo '<p>Gruppierung: <code>normalisierter Titel + Startdatum</code>. Wähle eine Gruppe aus und klicke „Zu einem Eintrag zusammenführen“.</p>';

    echo '<table class="widefat striped"><thead><tr><th>Gruppe</th><th>Events</th><th>Aktion</th></tr></thead><tbody>';
    foreach ($groups as $key => $ids) {
        $titles = array_map('get_the_title', $ids);
        $links  = array_map(function($id){
            return '<a href="'.esc_url(get_edit_post_link($id)).'">#'.$id.' „'.esc_html(get_the_title($id)).'“</a>';
        }, $ids);
        echo '<tr><td><code>'.esc_html($key).'</code></td><td>'.implode('<br>', $links).'</td><td>
            <form method="post" action="'.esc_url(admin_url('admin-post.php')).'">
              <input type="hidden" name="action" value="kse_merge_group">
              '.wp_nonce_field('kse_merge_group','kse_nonce',true,false).'
              <input type="hidden" name="ids" value="'.esc_attr(implode(',', $ids)).'">
              <button class="button button-primary">Zu einem Eintrag zusammenführen</button>
            </form>
        </td></tr>';
    }
    echo '</tbody></table></div>';
}

add_action('admin_post_kse_merge_group', function(){
    if (!current_user_can('edit_posts')) wp_die('no perms');
    check_admin_referer('kse_merge_group','kse_nonce');
    $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
    $res = kse_merge_event_group($ids);
    $msg = $res['merged']
        ? 'Zusammengeführt. Primär: #'.$res['primary'].'; Papierkorb: '.implode(',', $res['trashed'])
        : 'Keine Zusammenführung durchgeführt.';
    wp_redirect( add_query_arg(['page'=>'kse-dedupe','kse_notice'=>rawurlencode($msg)], admin_url('admin.php')) );
    exit;
});


/* -------------- Hilfen UI -------------- */

function kse_trim_text($text, $len = 220) {
    $t = trim(preg_replace('/\s+/u',' ', wp_strip_all_tags((string)$text)));
    if (mb_strlen($t) <= $len) return esc_html($t);
    return esc_html(mb_substr($t, 0, $len)).'…';
}

function kse_render_events_table_with_select(array $events, string $action, string $nonce_action, string $return_page) {
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="'.esc_attr($action).'" />';
    wp_nonce_field($nonce_action);
    echo '<input type="hidden" name="return_page" value="'.esc_attr($return_page).'" />';

    echo '<p style="display:flex;gap:.5rem;align-items:center;">
            <label><input type="checkbox" id="kse-select-all" /> Alle auswählen</label>
            <button type="submit" name="do" value="dry" class="button">Dry-Run (prüfen)</button>
            <button type="submit" name="do" value="live" class="button button-primary">Ausgewählte importieren</button>
          </p>';

    echo '<table class="widefat striped"><thead><tr>
            <th style="width:26px"></th>
            <th style="width:28%">Titel</th>
            <th style="width:14%">Start</th>
            <th style="width:20%">Ort</th>
            <th style="width:12%">Bild</th>
            <th>Beschreibung</th>
          </tr></thead><tbody>';

    foreach ($events as $ev) {
        $title = esc_html($ev['title'] ?? '');
        $start = esc_html($ev['start'] ?? ($ev['datetime'] ?? ''));
        $loc   = esc_html($ev['location'] ?? '');
        $src   = esc_url($ev['source_url'] ?? ($ev['source'] ?? ''));
        if (!$src) continue;

        $img   = '';
        if (!empty($ev['image'])) {
            $img = '<img src="'.esc_url($ev['image']).'" alt="" style="max-width:120px;height:auto;border-radius:4px" />';
        }
        $desc = kse_trim_text($ev['description'] ?? ($ev['description_html'] ?? ($ev['desc'] ?? '')));

        echo '<tr>
            <td><input type="checkbox" name="selected[]" value="'.esc_attr($src).'" /></td>
            <td>'.($src ? '<a href="'.$src.'" target="_blank" rel="noopener">'.$title.'</a>' : $title).'</td>
            <td>'.$start.'</td>
            <td>'.$loc.'</td>
            <td>'.$img.'</td>
            <td>'.$desc.'</td>
        </tr>';
    }

    echo '</tbody></table></form>';

    echo '<script>
    (function(){
      const all = document.getElementById("kse-select-all");
      if(!all) return;
      all.addEventListener("change", function(){
        document.querySelectorAll(\'input[name="selected[]"]\').forEach(cb => cb.checked = all.checked);
      });
    })();
    </script>';
}

/* -------------- Musik in alten Heidekirchen -------------- */

function kse_render_miah_admin() {
    echo '<div class="wrap"><h1>Musik in alten Heidekirchen – Parser</h1>';

    if (!class_exists('MusikInAltenHeidekirchenParser')) {
        echo '<div class="notice notice-error"><p>Der Parser ist nicht geladen. Bitte sicherstellen, dass <code>crawler/MusikInAltenHeidekirchenParser.php</code> in <code>veranstaltungs-export.php</code> eingebunden wird.</p></div></div>';
        return;
    }

    if (!empty($_GET['kse_msg'])) {
        echo '<div class="notice notice-success"><p>'.esc_html(wp_unslash($_GET['kse_msg'])).'</p></div>';
    }

    $parser  = new MusikInAltenHeidekirchenParser();
    $listUrl = defined('MusikInAltenHeidekirchenParser::LIST_URL')
        ? MusikInAltenHeidekirchenParser::LIST_URL
        : 'https://musik-in-alten-heidekirchen.wir-e.de/termine';

    $events = $parser->crawl($listUrl, 50);

    echo '<p><strong>Listen-URL:</strong> <a href="'.esc_url($listUrl).'" target="_blank" rel="noopener">'.esc_html($listUrl).'</a><br>';
    echo '<strong>Gefundene Events:</strong> '.count($events).'</p>';

    echo '<p>
        <form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:.5rem">
            '.wp_nonce_field('kse_import_miah_all', '_wpnonce', true, false).'
            <input type="hidden" name="action" value="kse_import_miah_all" />
            <button class="button">Alles prüfen (Dry-Run)</button>
        </form>
        <form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block">
            '.wp_nonce_field('kse_import_miah_all', '_wpnonce', true, false).'
            <input type="hidden" name="action" value="kse_import_miah_all" />
            <input type="hidden" name="do" value="live" />
            <button class="button button-primary">Alles importieren</button>
        </form>
    </p>';

    if (empty($events)) {
        echo '<p>Keine Veranstaltungen gefunden.</p></div>';
        return;
    }

    // Normieren
    $events = array_map(function($e){
        $e['source_url']  = $e['source_url'] ?? ($e['source'] ?? '');
        $e['description'] = $e['description'] ?? ($e['description_html'] ?? ($e['desc'] ?? ''));
        return $e;
    }, $events);

    kse_render_events_table_with_select($events, 'kse_import_miah', 'kse_import_miah', 'kse-miah');
    echo '</div>';
}

/* -------------- Gemeinde Seevetal -------------- */

function kse_render_seevetal_admin() {
    echo '<div class="wrap"><h1>Gemeinde Seevetal – Parser</h1>';

    if (!class_exists('SeevetalParser')) {
        echo '<div class="notice notice-warning"><p>Parser <code>SeevetalParser</code> nicht gefunden. Stelle sicher, dass <code>crawler/SeevetalParser.php</code> eingebunden ist.</p></div></div>';
        return;
    }

    if (!empty($_GET['kse_msg'])) {
        echo '<div class="notice notice-success"><p>'.esc_html(wp_unslash($_GET['kse_msg'])).'</p></div>';
    }

    $opts = get_option('kse_options', []);
    $wl = array_values(array_filter(array_map('trim', explode(',', $opts['seevetal_whitelist'] ?? 'Musik,Kultur'))));
    $bl = array_values(array_filter(array_map('trim', explode(',', $opts['seevetal_blacklist'] ?? ''))));

    echo '<p><strong>Whitelist:</strong> '.esc_html(implode(', ', $wl)).'</p>';
    if (!empty($bl)) echo '<p><strong>Blacklist:</strong> '.esc_html(implode(', ', $bl)).'</p>';

    $parser = new SeevetalParser();

    // Detail-Links sammeln
    $links = [];
    foreach ($wl as $term) {
        if ($term === '') continue;
        $links = array_merge($links, $parser->collect_detail_links($term));
    }
    $links = array_values(array_unique($links));
    $blacklisted = 0;

    // Blacklist anwenden
    if ($bl) {
        $links = array_values(array_filter($links, function ($u) use ($bl, &$blacklisted) {
            foreach ($bl as $b) {
                if ($b !== '' && stripos($u, $b) !== false) { $blacklisted++; return false; }
            }
            return true;
        }));
    }

    echo '<p>
        <form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:.5rem">
            '.wp_nonce_field('kse_import_seevetal_all', '_wpnonce', true, false).'
            <input type="hidden" name="action" value="kse_import_seevetal_all" />
            <button class="button">Alles prüfen (Dry-Run)</button>
        </form>
        <form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block">
            '.wp_nonce_field('kse_import_seevetal_all', '_wpnonce', true, false).'
            <input type="hidden" name="action" value="kse_import_seevetal_all" />
            <input type="hidden" name="do" value="live" />
            <button class="button button-primary">Alles importieren</button>
        </form>
    </p>';

    echo '<h2>Gefundene Detail-Links ('.count($links).')</h2>';
    if (!$links) {
        echo '<p>Keine Links gefunden.</p></div>';
        return;
    }

    // Probeauswertung (erste 20)
    $probe = [];
    foreach (array_slice($links, 0, 20) as $u) {
        $ev = $parser->get_cached_detail($u);
        if (!$ev) continue;

        $probe[] = [
            'title'       => $ev['title']     ?? '',
            'start'       => $ev['datetime']  ?? '',
            'location'    => $ev['location']  ?? '',
            'image'       => $ev['image']     ?? '',
            'description' => $ev['desc']      ?? ($ev['description'] ?? ''),
            'source_url'  => $u,
        ];
    }

    kse_render_events_table_with_select($probe, 'kse_import_seevetal', 'kse_import_seevetal', 'kse-seevetal');

    // Merke Blacklist-Zahl in verstecktem Feld (für Logging bei "Alles importieren")
    echo '<form id="kse-bl-hidden" style="display:none"><input type="hidden" id="kse-bl-count" value="'.esc_attr($blacklisted).'"></form>';

    echo '</div>';
}

/* ============ Import-Handler (Einzelauswahl) ============ */

add_action('admin_post_kse_import_miah', 'kse_handle_import_miah');
function kse_handle_import_miah() {
    if (!current_user_can('edit_posts')) wp_die('No permissions.');
    check_admin_referer('kse_import_miah');

    $urls = array_map('esc_url_raw', (array)($_POST['selected'] ?? []));
    $urls = array_values(array_unique(array_filter($urls)));

    $do   = sanitize_text_field($_POST['do'] ?? 'dry');

    if (!class_exists('MusikInAltenHeidekirchenParser') || empty($urls)) {
        wp_redirect(admin_url('admin.php?page=kse-miah&kse_msg='.rawurlencode('Keine Auswahl oder Parser nicht verfügbar.')));
        exit;
    }

    $parser   = new MusikInAltenHeidekirchenParser();
    $listUrl  = defined('MusikInAltenHeidekirchenParser::LIST_URL') ? MusikInAltenHeidekirchenParser::LIST_URL : '';
    $all      = $parser->crawl($listUrl ?: 'https://musik-in-alten-heidekirchen.wir-e.de/termine', 200);
    $byUrl    = [];
    foreach ($all as $e) {
        $u = $e['source_url'] ?? ($e['source'] ?? '');
        if ($u) $byUrl[$u] = $e;
    }

    $created = $updated = $skipped = 0;
    if ($do === 'live' && !function_exists('kse_tec_upsert_event')) {
        wp_redirect(admin_url('admin.php?page=kse-miah&kse_msg='.rawurlencode('Importer fehlt (includes/tec_import.php).')));
        exit;
    }

    foreach ($urls as $u) {
        if (empty($byUrl[$u])) { $skipped++; continue; }
        $ev = $byUrl[$u];
        $payload = [
            'title'       => $ev['title'] ?? '',
            'start'       => $ev['start'] ?? ($ev['datetime'] ?? ''),
            'description' => $ev['description'] ?? ($ev['description_html'] ?? ($ev['desc'] ?? '')),
            'location'    => $ev['location'] ?? '',
            'image'       => $ev['image'] ?? '',
            'source_url'  => $u,
        ];

        if ($do === 'dry') { $skipped++; continue; }

        $res = kse_tec_upsert_event($payload, [
            'default_duration_minutes' => 120,
            'category'                 => 'Musik in alten Heidekirchen',
        ]);

        if (($res['action'] ?? '') === 'updated') $updated++;
        elseif (($res['action'] ?? '') === 'created') $created++;
        else $skipped++;

        $result = kse_tec_upsert_event($event, [
        'default_duration_minutes' => 120,
        'category' => $quelle === 'miah' ? 'Musik in alten Heidekirchen' : 'Gemeinde Seevetal',
        ]);

        $tec_link = !empty($result['permalink']) ? $result['permalink'] : '';
        $src_link = !empty($event['source_url']) ? $event['source_url'] : '';

        printf(
        '<li><strong>%s</strong> — <a href="%s" target="_blank" rel="noopener">Quelle</a> → <a href="%s" target="_blank" rel="noopener">TEC #%d</a> <em>(%s)</em></li>',
        esc_html($event['title'] ?? '(ohne Titel)'),
        esc_url($src_link),
        esc_url($tec_link),
        (int)($result['post_id'] ?? 0),
        esc_html($result['action'] ?? '')
        );

    }

    if ($do === 'live' && function_exists('kse_stats_log')) {
        kse_stats_log('MIAH', [
            'scanned' => count($urls),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'blacklisted' => 0
        ]);
    }

    $msg = ($do === 'dry')
        ? 'Dry-Run erledigt: '.count($urls).' Auswahl(en).'
        : "Import fertig: erstellt $created, aktualisiert $updated, übersprungen $skipped.";
    wp_redirect(admin_url('admin.php?page=kse-miah&kse_msg='.rawurlencode($msg)));
    exit;
}

add_action('admin_post_kse_import_seevetal', 'kse_handle_import_seevetal');
function kse_handle_import_seevetal() {
    if (!current_user_can('edit_posts')) wp_die('No permissions.');
    check_admin_referer('kse_import_seevetal');

    $urls = array_map('esc_url_raw', (array)($_POST['selected'] ?? []));
    $urls = array_values(array_unique(array_filter($urls)));

    $do   = sanitize_text_field($_POST['do'] ?? 'dry');

    if (!class_exists('SeevetalParser') || empty($urls)) {
        wp_redirect(admin_url('admin.php?page=kse-seevetal&kse_msg='.rawurlencode('Keine Auswahl oder Parser nicht verfügbar.')));
        exit;
    }
    if ($do === 'live' && !function_exists('kse_tec_upsert_event')) {
        wp_redirect(admin_url('admin.php?page=kse-seevetal&kse_msg='.rawurlencode('Importer fehlt (includes/tec_import.php).')));
        exit;
    }

    $parser  = new SeevetalParser();
    $created = $updated = $skipped = 0;

    foreach ($urls as $u) {
        $ev = $parser->get_cached_detail($u);
        if (!$ev) { $skipped++; continue; }

        $payload = [
            'title'       => $ev['title'] ?? '',
            'start'       => $ev['datetime'] ?? '',
            'description' => $ev['desc'] ?? ($ev['description'] ?? ''),
            'location'    => $ev['location'] ?? '',
            'image'       => $ev['image'] ?? '',
            'source_url'  => $u,
        ];

        if ($do === 'dry') { $skipped++; continue; }

        $res = kse_tec_upsert_event($payload, [
            'default_duration_minutes' => 120,
            'category'                 => 'Gemeinde Seevetal', // <-- angepasst
        ]);

        if (($res['action'] ?? '') === 'updated') $updated++;
        elseif (($res['action'] ?? '') === 'created') $created++;
        else $skipped++;

        $result = kse_tec_upsert_event($event, [
        'default_duration_minutes' => 120,
        'category' => $quelle === 'miah' ? 'Musik in alten Heidekirchen' : 'Gemeinde Seevetal',
        ]);

        $tec_link = !empty($result['permalink']) ? $result['permalink'] : '';
        $src_link = !empty($event['source_url']) ? $event['source_url'] : '';

        printf(
        '<li><strong>%s</strong> — <a href="%s" target="_blank" rel="noopener">Quelle</a> → <a href="%s" target="_blank" rel="noopener">TEC #%d</a> <em>(%s)</em></li>',
        esc_html($event['title'] ?? '(ohne Titel)'),
        esc_url($src_link),
        esc_url($tec_link),
        (int)($result['post_id'] ?? 0),
        esc_html($result['action'] ?? '')
        );

    }

    if ($do === 'live' && function_exists('kse_stats_log')) {
        kse_stats_log('SEEVETAL', [
            'scanned' => count($urls),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'blacklisted' => 0
        ]);
    }

    $msg = ($do === 'dry')
        ? 'Dry-Run erledigt: '.count($urls).' Auswahl(en).'
        : "Import fertig: erstellt $created, aktualisiert $updated, übersprungen $skipped.";
    wp_redirect(admin_url('admin.php?page=kse-seevetal&kse_msg='.rawurlencode($msg)));
    exit;
}

/* ============ Alles-importieren (Komfort) ============ */

add_action('admin_post_kse_import_miah_all', 'kse_handle_import_miah_all');
function kse_handle_import_miah_all() {
    if (!current_user_can('edit_posts')) wp_die('No permissions.');
    check_admin_referer('kse_import_miah_all');

    $do = sanitize_text_field($_POST['do'] ?? 'dry');

    if (!class_exists('MusikInAltenHeidekirchenParser')) {
        wp_redirect(admin_url('admin.php?page=kse-miah&kse_msg='.rawurlencode('Parser nicht verfügbar.')));
        exit;
    }
    $parser  = new MusikInAltenHeidekirchenParser();
    $listUrl = defined('MusikInAltenHeidekirchenParser::LIST_URL')
        ? MusikInAltenHeidekirchenParser::LIST_URL
        : 'https://musik-in-alten-heidekirchen.wir-e.de/termine';
    $events  = $parser->crawl($listUrl, 200);

    $scanned = count($events);
    $created = $updated = $skipped = 0;

    if ($do === 'live' && !function_exists('kse_tec_upsert_event')) {
        wp_redirect(admin_url('admin.php?page=kse-miah&kse_msg='.rawurlencode('Importer fehlt (includes/tec_import.php).')));
        exit;
    }

    foreach ($events as $ev) {
        $src = $ev['source_url'] ?? ($ev['source'] ?? '');
        if (!$src) { $skipped++; continue; }

        if ($do === 'dry') { $skipped++; continue; }

        $res = kse_tec_upsert_event([
            'title'       => $ev['title'] ?? '',
            'start'       => $ev['start'] ?? ($ev['datetime'] ?? ''),
            'description' => $ev['description'] ?? ($ev['description_html'] ?? ($ev['desc'] ?? '')),
            'location'    => $ev['location'] ?? '',
            'image'       => $ev['image'] ?? '',
            'source_url'  => $src,
        ], [
            'default_duration_minutes' => 120,
            'category'                 => 'Musik in alten Heidekirchen',
        ]);

        if (($res['action'] ?? '') === 'updated') $updated++;
        elseif (($res['action'] ?? '') === 'created') $created++;
        else $skipped++;

        $result = kse_tec_upsert_event($event, [
        'default_duration_minutes' => 120,
        'category' => $quelle === 'miah' ? 'Musik in alten Heidekirchen' : 'Gemeinde Seevetal',
        ]);

        $tec_link = !empty($result['permalink']) ? $result['permalink'] : '';
        $src_link = !empty($event['source_url']) ? $event['source_url'] : '';

        printf(
        '<li><strong>%s</strong> — <a href="%s" target="_blank" rel="noopener">Quelle</a> → <a href="%s" target="_blank" rel="noopener">TEC #%d</a> <em>(%s)</em></li>',
        esc_html($event['title'] ?? '(ohne Titel)'),
        esc_url($src_link),
        esc_url($tec_link),
        (int)($result['post_id'] ?? 0),
        esc_html($result['action'] ?? '')
        );

    }

    if ($do === 'live' && function_exists('kse_stats_log')) {
        kse_stats_log('MIAH', compact('scanned','created','updated','skipped') + ['blacklisted'=>0]);
    }

    $msg = ($do === 'dry')
        ? "Dry-Run: gesamt $scanned Einträge geprüft."
        : "Alles importiert: erstellt $created, aktualisiert $updated, übersprungen $skipped (gesamt $scanned).";
    wp_redirect(admin_url('admin.php?page=kse-miah&kse_msg='.rawurlencode($msg)));
    exit;
}

add_action('admin_post_kse_import_seevetal_all', 'kse_handle_import_seevetal_all');
function kse_handle_import_seevetal_all() {
    if (!current_user_can('edit_posts')) wp_die('No permissions.');
    check_admin_referer('kse_import_seevetal_all');

    $do = sanitize_text_field($_POST['do'] ?? 'dry');

    if (!class_exists('SeevetalParser')) {
        wp_redirect(admin_url('admin.php?page=kse-seevetal&kse_msg='.rawurlencode('Parser nicht verfügbar.')));
        exit;
    }

    $opts = get_option('kse_options', []);
    $wl = array_values(array_filter(array_map('trim', explode(',', $opts['seevetal_whitelist'] ?? 'Musik,Kultur'))));
    $bl = array_values(array_filter(array_map('trim', explode(',', $opts['seevetal_blacklist'] ?? ''))));

    $parser = new SeevetalParser();
    $links = [];
    foreach ($wl as $term) {
        if ($term === '') continue;
        $links = array_merge($links, $parser->collect_detail_links($term));
    }
    $links = array_values(array_unique($links));

    $blacklisted = 0;
    if ($bl) {
        $links = array_values(array_filter($links, function ($u) use ($bl, &$blacklisted) {
            foreach ($bl as $b) {
                if ($b !== '' && stripos($u, $b) !== false) { $blacklisted++; return false; }
            }
            return true;
        }));
    }

    $scanned = count($links);
    $created = $updated = $skipped = 0;

    if ($do === 'live' && !function_exists('kse_tec_upsert_event')) {
        wp_redirect(admin_url('admin.php?page=kse-seevetal&kse_msg='.rawurlencode('Importer fehlt (includes/tec_import.php).')));
        exit;
    }

    foreach ($links as $u) {
        $ev = $parser->get_cached_detail($u);
        if (!$ev) { $skipped++; continue; }

        if ($do === 'dry') { $skipped++; continue; }

        $res = kse_tec_upsert_event([
            'title'       => $ev['title'] ?? '',
            'start'       => $ev['datetime'] ?? '',
            'description' => $ev['desc'] ?? ($ev['description'] ?? ''),
            'location'    => $ev['location'] ?? '',
            'image'       => $ev['image'] ?? '',
            'source_url'  => $u,
        ], [
            'default_duration_minutes' => 120,
            'category'                 => 'Gemeinde Seevetal',
        ]);

        if (($res['action'] ?? '') === 'updated') $updated++;
        elseif (($res['action'] ?? '') === 'created') $created++;
        else $skipped++;

        $result = kse_tec_upsert_event($event, [
        'default_duration_minutes' => 120,
        'category' => $quelle === 'miah' ? 'Musik in alten Heidekirchen' : 'Gemeinde Seevetal',
        ]);

        $tec_link = !empty($result['permalink']) ? $result['permalink'] : '';
        $src_link = !empty($event['source_url']) ? $event['source_url'] : '';

        printf(
        '<li><strong>%s</strong> — <a href="%s" target="_blank" rel="noopener">Quelle</a> → <a href="%s" target="_blank" rel="noopener">TEC #%d</a> <em>(%s)</em></li>',
        esc_html($event['title'] ?? '(ohne Titel)'),
        esc_url($src_link),
        esc_url($tec_link),
        (int)($result['post_id'] ?? 0),
        esc_html($result['action'] ?? '')
        );

    }

    if ($do === 'live' && function_exists('kse_stats_log')) {
        kse_stats_log('SEEVETAL', compact('scanned','created','updated','skipped') + ['blacklisted'=>$blacklisted]);
    }

    $msg = ($do === 'dry')
        ? "Dry-Run: gesamt $scanned Einträge geprüft; Blacklist: $blacklisted."
        : "Alles importiert: erstellt $created, aktualisiert $updated, übersprungen $skipped; Blacklist: $blacklisted (gesamt $scanned).";
    wp_redirect(admin_url('admin.php?page=kse-seevetal&kse_msg='.rawurlencode($msg)));
    exit;
}
