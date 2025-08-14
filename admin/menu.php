<?php
if (!defined('ABSPATH')) { exit; }

/* ==================== Top: Dashboard ==================== */
function kse_render_dashboard() { ?>
  <div class="wrap">
    <h1>Event Import – Übersicht</h1>
    <p>Wähle links eine Quelle für Dry-Run oder Import.</p>
    <ul style="list-style:disc;margin-left:1.5em;">
      <li><a href="<?php echo esc_url( admin_url('admin.php?page=kse-miah') ); ?>">Musik in alten Heidekirchen</a></li>
      <li><a href="<?php echo esc_url( admin_url('admin.php?page=kse-seevetal') ); ?>">Seevetal Gemeinde</a></li>
    </ul>
  </div>
<?php }

/* ==================== Musik in alten Heidekirchen ==================== */
function kse_render_miah_admin() {
    if (!current_user_can('manage_options')) return;

    $action = isset($_POST['kse_action']) ? sanitize_text_field($_POST['kse_action']) : '';
    $result = null; $logs = [];

    if ($action === 'dry') {
        $result = kse_run_source('musikheide', '', false, $logs);
        kse_store_rows_transient('musikheide', $result['rows']);
    } elseif ($action === 'live_selected') {
        $rows = kse_get_rows_transient('musikheide');
        $sel  = isset($_POST['rows']) && is_array($_POST['rows']) ? array_map('intval', $_POST['rows']) : [];
        $filtered = [];
        foreach ($sel as $idx) { if (isset($rows[$idx])) $filtered[] = $rows[$idx]; }
        $result = kse_import_selected('musikheide', $filtered, $logs);
    }

    ?>
    <div class="wrap">
        <h1>Musik in alten Heidekirchen – Parser</h1>
        <p><em>Hinweis:</em> Diese Quelle ignoriert Suchbegriffe und liest <code>/termine</code> komplett aus.</p>

        <form method="post" style="margin-bottom:1em;">
            <button class="button button-secondary" name="kse_action" value="dry">Dry-Run ausführen</button>
        </form>

        <?php kse_render_result_with_selection($result, $logs, 'musikheide'); ?>
    </div>
    <?php
}

/* ==================== Seevetal Gemeinde ==================== */
function kse_render_seevetal_admin() {
    if (!current_user_can('manage_options')) return;

    $terms = isset($_POST['terms']) ? sanitize_text_field($_POST['terms']) : get_option('kse_terms_seevetal', '');
    $action = isset($_POST['kse_action']) ? sanitize_text_field($_POST['kse_action']) : '';
    $result = null; $logs = [];

    if ($action === 'dry') {
        if (trim($terms) === '') { $logs[] = 'Bitte mindestens ein Suchwort (z. B. „Musik, Kultur“) eingeben.'; }
        else {
            update_option('kse_terms_seevetal', $terms, false);
            $result = kse_run_source('seevetal', $terms, false, $logs);
            kse_store_rows_transient('seevetal', $result['rows']);
        }
    } elseif ($action === 'live_selected') {
        $rows = kse_get_rows_transient('seevetal');
        $sel  = isset($_POST['rows']) && is_array($_POST['rows']) ? array_map('intval', $_POST['rows']) : [];
        $filtered = [];
        foreach ($sel as $idx) { if (isset($rows[$idx])) $filtered[] = $rows[$idx]; }
        $result = kse_import_selected('seevetal', $filtered, $logs);
    }

    ?>
    <div class="wrap">
        <h1>Seevetal Gemeinde – Parser</h1>
        <form method="post">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="terms">Suchbegriffe</label></th>
                    <td>
                        <input type="text" id="terms" name="terms" class="regular-text" value="<?php echo esc_attr($terms); ?>" placeholder="z. B. Musik, Kultur">
                        <p class="description">Mehrere Begriffe mit Komma/Leerzeichen trennen. Es werden je Begriff eigene Listen-URLs abgerufen.</p>
                    </td>
                </tr>
            </table>
            <p>
                <button class="button button-secondary" name="kse_action" value="dry">Dry-Run anzeigen</button>
            </p>
        </form>

        <?php kse_render_result_with_selection($result, $logs, 'seevetal'); ?>
    </div>
    <?php
}

/* ==================== Einstellungen (Platzhalter) ==================== */
function kse_render_settings_admin() { ?>
  <div class="wrap"><h1>Einstellungen</h1><p>Mehr in Kürze.</p></div>
<?php }

/* =====================================================================
 * Gemeinsame Ausführung
 * ===================================================================*/

function kse_run_source(string $source, string $terms, bool $do_import, array &$logs) {
    $data = ['links'=>[], 'events'=>[]]; $list_urls = [];

    try {
        if ($source === 'musikheide') {
            if (!class_exists('MusikInAltenHeidekirchenParser')) throw new RuntimeException('Parser nicht geladen.');
            $data = MusikInAltenHeidekirchenParser::crawl('');
            $list_urls = $data['list_urls'] ?? [MusikInAltenHeidekirchenParser::LIST];
        } elseif ($source === 'seevetal') {
            if (!class_exists('SeevetalParser')) throw new RuntimeException('Parser nicht geladen.');
            $data = SeevetalParser::crawl($terms);
            // tatsächliche Listen-URLs für Anzeige
            $norm = kse_normalize_terms($terms);
            foreach ($norm as $t) {
                $list_urls[] = SeevetalParser::BASE_URL . '/regional/veranstaltungen/sucheplus2.html?schnellauswahl=0&suchwort=' . rawurlencode($t) . '&beginn_datum=&ende_datum=&ort=0';
            }
        } else {
            throw new RuntimeException('Unbekannte Quelle: ' . $source);
        }
    } catch (Throwable $e) {
        $logs[] = 'Fehler: ' . $e->getMessage();
        return ['source'=>$source, 'list_urls'=>$list_urls, 'links'=>[], 'rows'=>[], 'imported'=>[]];
    }

    // Normalisiere Events zu Rows
    $rows = [];
    foreach ((array)$data['events'] as $ev) { $rows[] = kse_map_event($ev, $source); }

    // Kein Auto-Import hier; Import erfolgt über „live_selected“
    return [
        'source'    => $source,
        'list_urls' => $list_urls,
        'links'     => (array)($data['links'] ?? []),
        'rows'      => $rows,
        'imported'  => [],
    ];
}

/* Ausgewählte Rows importieren (Checkbox-Import) */
function kse_import_selected(string $source, array $rows, array &$logs) {
    $imported = [];
    foreach ($rows as $row) {
        $res = kse_import_event_to_tec($row);
        if (is_wp_error($res)) {
            $logs[] = 'ERR: '.$row['title'].' → '.$res->get_error_message();
        } else {
            $logs[] = 'OK: '.$row['title'].' → Event #'.(int)$res;
            $imported[] = (int)$res;
        }
    }
    return ['source'=>$source,'list_urls'=>[],'links'=>[],'rows'=>$rows,'imported'=>$imported];
}

/* Anzeige: Logs + URLs + Tabelle mit Checkboxen + Import-Button */
function kse_render_result_with_selection($result, array $logs, string $source_slug) {
    if (!is_array($result)) {
        if (!empty($logs)) echo '<div class="notice notice-error"><p>'.esc_html(implode(' ', $logs)).'</p></div>';
        return;
    }

    if (!empty($logs)) {
        echo '<div class="notice notice-info"><p>'.esc_html(implode(' ', $logs)).'</p></div>';
    }

    if (!empty($result['list_urls'])) {
        echo '<h2>Listen-URLs</h2><ul>';
        foreach ($result['list_urls'] as $u) {
            echo '<li><a target="_blank" href="'.esc_url($u).'">'.esc_html($u).'</a></li>';
        }
        echo '</ul>';
    }

    if (!empty($result['links'])) {
        echo '<h2>Gefundene Detail-Links ('.count($result['links']).')</h2><ol>';
        foreach ($result['links'] as $u) {
            echo '<li><a target="_blank" href="'.esc_url($u).'">'.esc_html($u).'</a></li>';
        }
        echo '</ol>';
    }

    if (!empty($result['rows'])) {
        echo '<h2>Vorschau & Auswahl</h2>';
        echo '<form method="post"><input type="hidden" name="kse_action" value="live_selected" />';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:30px"><input type="checkbox" onclick="jQuery(\'.kse-row\').prop(\'checked\', this.checked)" /></th>';
        echo '<th>Titel</th><th>Start</th><th>Ort</th><th>Beschreibung</th><th>Bild</th>';
        echo '</tr></thead><tbody>';
        foreach ($result['rows'] as $i => $r) {
            $desc = wp_strip_all_tags($r['description']);
            echo '<tr>';
            echo '<td><input class="kse-row" type="checkbox" name="rows[]" value="'.(int)$i.'" checked /></td>';
            echo '<td>'.esc_html($r['title']).'</td>';
            echo '<td>'.esc_html($r['start']).'</td>';
            echo '<td>'.esc_html(trim(($r['venue_name'] ?: $r['venue']).' '.$r['venue_address'].' '.$r['venue_postcode'].' '.$r['venue_city'])).'</td>';
            echo '<td>'.esc_html(wp_html_excerpt($desc, 160, '…')).'</td>';
            echo '<td>'.($r['image'] ? '<img src="'.esc_url($r['image']).'" style="max-width:80px;height:auto;border:1px solid #ddd;padding:2px;background:#fff;" />' : '—').'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><button class="button button-primary">Ausgewählte importieren</button></p>';
        echo '</form>';
    }

    if (!empty($result['imported'])) {
        echo '<h2>Importiert</h2><ul>';
        foreach ($result['imported'] as $pid) {
            echo '<li>Event #'.(int)$pid.' – <a target="_blank" href="'.esc_url(admin_url('post.php?post='.$pid.'&action=edit')).'">bearbeiten</a></li>';
        }
        echo '</ul>';
    }
}

/* Transient-Speicher für Rows je Quelle/User (10 Minuten) */
function kse_store_rows_transient(string $source, array $rows): void {
    $key = 'kse_rows_'.$source.'_'.get_current_user_id();
    set_transient($key, $rows, 10 * MINUTE_IN_SECONDS);
}
function kse_get_rows_transient(string $source): array {
    $key = 'kse_rows_'.$source.'_'.get_current_user_id();
    $rows = get_transient($key);
    return is_array($rows) ? $rows : [];
}

/* ===== Mapping & Utilities (aus deiner aktuellen Version) ===== */

function kse_map_event(array $ev, string $source): array {
    // … diese Funktion bitte aus deiner aktuellen Datei übernehmen (unverändert) …
    // Falls nicht vorhanden, nimm die letzte Version von mir.
    return [
        'source'         => $ev['source'] ?? $source,
        'external_id'    => $ev['external_id'] ?? ($source.':'.md5(($ev['source_url']??'').($ev['title']??''))),
        'title'          => (string)($ev['title'] ?? ''),
        'description'    => (string)($ev['description'] ?? ''),
        'start'          => (string)($ev['start'] ?? ''),
        'end'            => (string)($ev['end'] ?? ''),
        'venue'          => (string)($ev['venue'] ?? ''),
        'venue_name'     => (string)($ev['venue_name'] ?? ''),
        'venue_address'  => (string)($ev['venue_address'] ?? ''),
        'venue_postcode' => (string)($ev['venue_postcode'] ?? ''),
        'venue_city'     => (string)($ev['venue_city'] ?? ''),
        'image'          => (string)($ev['image'] ?? ''),
        'source_url'     => (string)($ev['source_url'] ?? ''),
    ];
}

function kse_normalize_terms(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];
    $norm = preg_split('~[,\s]+~u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(array_map('trim', $norm)));
}

/* ===== Stabiler LIVE-Import (keine tribe_create_event, robuste Metas) ===== */

function kse_import_event_to_tec(array $row) {
    $title = trim((string)($row['title'] ?? ''));
    $start = (string)($row['start'] ?? '');
    $end   = (string)($row['end'] ?? '');
    $desc  = (string)($row['description'] ?? '');
    $image = (string)($row['image'] ?? '');
    $venue_name     = (string)($row['venue_name'] ?? ($row['venue'] ?? ''));
    $venue_street   = (string)($row['venue_address'] ?? '');
    $venue_postcode = (string)($row['venue_postcode'] ?? '');
    $venue_city     = (string)($row['venue_city'] ?? '');
    $source_url     = (string)($row['source_url'] ?? '');
    $external_id    = (string)($row['external_id'] ?? '');
    $source         = (string)($row['source'] ?? '');

    if ($title === '') return new WP_Error('kse_missing', 'Titel fehlt');

    // Zeit normalisieren
    if (!preg_match('~^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$~', $start)) {
        [$start, $end] = kse_normalize_german_datetime_range($start, $end);
    }
    if ($start === '') return new WP_Error('kse_missing', 'Startzeit unlesbar');
    if ($end === '') $end = $start;

    // Duplikate
    $post_id = 0;
    if ($external_id !== '') {
        $post_id = kse_find_tec_by_external_id($external_id) ?: 0;
    }
    if (!$post_id) {
        $post_id = kse_find_tec_by_title_and_startmeta($title, $start) ?: 0;
    }

    $tz = function_exists('wp_timezone_string') ? (wp_timezone_string() ?: 'Europe/Berlin') : 'Europe/Berlin';
    $base = [
        'post_title'   => $title,
        'post_content' => $desc,
        'post_status'  => 'publish',
        'post_type'    => 'tribe_events',
    ];

    if ($post_id) {
        $base['ID'] = $post_id;
        $u = wp_update_post($base, true);
        if (is_wp_error($u)) return $u;
    } else {
        $post_id = wp_insert_post($base, true);
        if (is_wp_error($post_id) || !$post_id) return new WP_Error('kse_insert_failed', 'Event konnte nicht angelegt werden');
    }

    // Event-Metas
    update_post_meta($post_id, '_EventStartDate', $start);
    update_post_meta($post_id, '_EventEndDate',   $end);
    $s_utc = get_gmt_from_date($start, 'Y-m-d H:i:s');
    $e_utc = get_gmt_from_date($end,   'Y-m-d H:i:s');
    update_post_meta($post_id, '_EventStartDateUTC', $s_utc);
    update_post_meta($post_id, '_EventEndDateUTC',   $e_utc);
    update_post_meta($post_id, '_EventTimezone',     $tz);
    update_post_meta($post_id, '_EventDuration',     max(0, strtotime($e_utc) - strtotime($s_utc)));

    // Venue (einfach)
    if ($venue_name !== '') {
        $venue_id = kse_upsert_tribe_venue($venue_name, $venue_street, $venue_postcode.' '.$venue_city);
        if ($venue_id) update_post_meta($post_id, '_EventVenueID', (int)$venue_id);
    }

    // Bild
    if ($image !== '') {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
        }
        $att_id = kse_sideload_image($image, $post_id);
        if (!is_wp_error($att_id) && $att_id) set_post_thumbnail($post_id, $att_id);
    }

    // Quelle / externe ID
    if ($source_url)   update_post_meta($post_id, '_kse_source_url', esc_url_raw($source_url));
    if ($external_id) {
        update_post_meta($post_id, '_kse_external_id', $external_id);
        $index = get_option('kse_import_index', []);
        $index[$external_id] = (int)$post_id;
        update_option('kse_import_index', $index, false);
    }

    // Kategorie (wenn vorhanden)
    if ($source === 'musikheide' && taxonomy_exists('tribe_events_cat')) {
        wp_set_object_terms($post_id, ['Musik in alten Heidekirchen'], 'tribe_events_cat', true);
    }

    return (int)$post_id;
}

/** Suche via Meta (_kse_external_id) */
function kse_find_tec_by_external_id(string $external_id): ?int {
    $q = new WP_Query([
        'post_type'      => 'tribe_events',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'meta_query'     => [[ 'key' => '_kse_external_id', 'value' => $external_id ]],
        'fields'         => 'ids',
    ]);
    return ($q->have_posts()) ? (int)$q->posts[0] : null;
}

/** Duplikatheuristik: Titel ~gleich & _EventStartDate exakt */
function kse_find_tec_by_title_and_startmeta(string $title, string $start): ?int {
    $q = new WP_Query([
        'post_type'      => 'tribe_events',
        'post_status'    => 'any',
        'posts_per_page' => 20,
        'no_found_rows'  => true,
        'meta_query'     => [[ 'key' => '_EventStartDate', 'value' => $start ]],
        'fields'         => 'ids',
        's'              => $title,
    ]);
    if ($q->have_posts()) {
        foreach ($q->posts as $pid) {
            if (strcasecmp(get_the_title($pid), $title) === 0) return (int)$pid;
        }
        return (int)$q->posts[0];
    }
    return null;
}

/** Venue upsert (sehr simpel) */
function kse_upsert_tribe_venue(string $name, string $street = '', string $cityline = ''): ?int {
    // Suche existierenden Venue (CPT: tribe_venue)
    $q = new WP_Query([
        'post_type'      => 'tribe_venue',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'title'          => $name,
        's'              => $name,
        'fields'         => 'ids',
    ]);
    $id = $q->have_posts() ? (int)$q->posts[0] : 0;
    if (!$id) {
        $id = wp_insert_post([
            'post_title'   => $name,
            'post_type'    => 'tribe_venue',
            'post_status'  => 'publish',
        ], true);
        if (is_wp_error($id) || !$id) return null;
    }
    if ($street)  update_post_meta($id, '_VenueAddress',  $street);
    if ($cityline) {
        if (preg_match('~(\d{5})\s+(.+)~', $cityline, $m)) {
            update_post_meta($id, '_VenueZip',  $m[1]);
            update_post_meta($id, '_VenueCity', $m[2]);
        } else {
            update_post_meta($id, '_VenueCity', $cityline);
        }
    }
    return (int)$id;
}

/** Sideload-Helper */
if (!function_exists('kse_sideload_image')) {
    function kse_sideload_image(string $url, int $post_id) {
        $tmp = download_url($url);
        if (is_wp_error($tmp)) return $tmp;
        $file_array = [ 'name' => basename(parse_url($url, PHP_URL_PATH)), 'tmp_name' => $tmp ];
        $att_id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($att_id)) { @unlink($tmp); return $att_id; }
        return $att_id;
    }
}

/* ===== Datum-Normalisierung: gleiche Logik wie zuvor ===== */
function kse_normalize_german_datetime_range(string $start_raw, string $end_raw = ''): array {
    $to_mysql = function (string $s): string {
        $s = trim($s);
        $s = preg_replace('~(?:Mo|Di|Mi|Do|Fr|Sa|So)\.,?\s*~u', '', $s);
        $s = str_replace('Uhr', '', $s);
        $s = trim(str_replace(',', '', $s));
        if (preg_match('~(\d{2})\.(\d{2})\.(\d{4})\s+(\d{1,2}):(\d{2})~', $s, $m)) {
            return sprintf('%04d-%02d-%02d %02d:%02d:00', $m[3], $m[2], $m[1], $m[4], $m[5]);
        }
        if (preg_match('~(\d{2})\.(\d{2})\.(\d{4})~', $s, $m)) {
            return sprintf('%04d-%02d-%02d 00:00:00', $m[3], $m[2], $m[1]);
        }
        return '';
    };
    if ($end_raw === '' && strpos($start_raw, ' - ') !== false) {
        [$lhs, $rhs] = array_map('trim', explode(' - ', $start_raw, 2));
        return [$to_mysql($lhs), $to_mysql($rhs)];
    }
    $start = $to_mysql($start_raw);
    $end   = $end_raw !== '' ? $to_mysql($end_raw) : $start;
    return [$start, $end];
}
