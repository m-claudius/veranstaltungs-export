<?php
if (!defined('ABSPATH')) exit;

/**
 * Menüstruktur:
 * Event Import
 *  ├─ Musik in alten Heidekirchen
 *  ├─ Seevetal Gemeinde
 *  ├─ Dry Run → The Events Calendar
 *  └─ Einstellungen
 */


add_action('admin_menu', function () {

    // Top-Level
    add_menu_page(
        'Event Import',
        'Event Import',
        'manage_options',
        'kse_event_import',
        'kse_render_event_import_dashboard',
        'dashicons-calendar-alt',
        58
    );

    // Musik in alten Heidekirchen (Renderfunktion kommt aus dem Parser-File)
    add_submenu_page(
        'kse_event_import',
        'Musik in alten Heidekirchen',
        'Musik in alten Heidekirchen',
        'manage_options',
        'kse_musikheide',
        function () {
            if (function_exists('kse_render_musikheide_parser_admin')) {
                kse_render_musikheide_parser_admin();
            } else {
                echo '<div class="notice notice-error"><p>Parser-UI nicht geladen. Prüfe Einbindung von <code>crawler/MusikInAltenHeidekirchenParser.php</code>.</p></div>';
            }
        }
    );

// Seevetal: Nur Platzhalter registrieren, WENN die Klasse keine eigene Admin-Seite einhängt
if ( ! class_exists('SeevetalExporter') || ! method_exists('SeevetalExporter', 'render_admin_page') ) {
    add_submenu_page(
        'kse_event_import',
        'Seevetal Gemeinde',
        'Seevetal Gemeinde',
        'manage_options',
        'kse_seevetal',
        'kse_render_seevetal_placeholder'
    );
}


    // DRY RUN
    add_submenu_page(
        'kse_event_import',
        'Dry Run → The Events Calendar',
        'Dry Run → The Events Calendar',
        'manage_options',
        'kse_dry_run',
        'kse_render_dry_run'
    );

        add_submenu_page(
        'kse_event_import',
        'Import (LIVE) → The Events Calendar',
        'Import (LIVE) → The Events Calendar',
        'manage_options',
        'kse_import_live',
        'kse_render_import_live'
    );


    // Einstellungen (minimal)
    add_submenu_page(
        'kse_event_import',
        'Einstellungen',
        'Einstellungen',
        'manage_options',
        'kse_settings',
        'kse_render_settings'
    );
});

/* ===================== Dashboard ===================== */
function kse_render_event_import_dashboard() { ?>
    <div class="wrap">
        <h1>Event Import</h1>
        <p>Wähle links eine Quelle oder führe einen <strong>Dry Run</strong> für The Events Calendar aus.</p>
        <ul>
            <li>Musik in alten Heidekirchen – Parser</li>
            <li>Seevetal Gemeinde – Parser</li>
            <li>Dry Run → prüft Duplikate (Titel + Start) gegen TEC, speichert Protokoll</li>
        </ul>
    </div>
<?php }

/* ================ Seevetal Platzhalter ================ */
function kse_render_seevetal_placeholder() { ?>
    <div class="wrap">
        <h1>Seevetal Gemeinde</h1>
        <?php if (class_exists('SeevetalParser')): ?>
            <p>Der Parser ist vorhanden, aber keine Admin-UI registriert. (Optional: eigene UI hinzufügen.)</p>
        <?php else: ?>
            <div class="notice notice-warning"><p>Kein <code>SeevetalParser</code> gefunden. Datei <code>crawler/SeevetalParser.php</code> einbinden.</p></div>
        <?php endif; ?>
        <p>Nutze vorerst den <em>Dry Run</em>, um die Quelle Seevetal mitzunehmen.</p>
    </div>
<?php }

/* ================== DRY RUN – TEC ===================== */
/**
 * DRY RUN: crawlt ausgewählte Quellen, mappt Felder,
 * prüft Duplikate in TEC (Titel + _EventStartDate),
 * schreibt ein Protokoll in wp_options: kse_last_dry_run
 */
function kse_render_dry_run()
{
    // Form-Defaults
    $defaults = [
        'use_musik'     => '1',
        'musik_url'     => class_exists('MusikInAltenHeidekirchenParser') ? MusikInAltenHeidekirchenParser::LIST_URL : '',
        'musik_limit'   => 10,
        'use_seevetal'  => '',
        'seevetal_url'  => '',
        'seevetal_limit'=> 10,
    ];
    $input = array_merge($defaults, array_map('strval', $_POST ?? []));

    $do_run  = isset($_POST['kse_dry_run']);
    $do_save = isset($_POST['kse_dry_run_save']);

    $report = [
        'run_at' => current_time('mysql'),
        'items'  => [], // jeweils: source, external_id, title, start, venue, status, reason, source_url, image
        'summary'=> ['NEU'=>0, 'SKIP'=>0, 'UPDATE'=>0, 'FEHLER'=>0]
    ];

    if ($do_run) {
        // 1) Events sammeln
        $events = [];

        if ($input['use_musik'] && class_exists('MusikInAltenHeidekirchenParser')) {
            $arr = MusikInAltenHeidekirchenParser::crawl($input['musik_url'], (int)$input['musik_limit']);
            foreach ($arr as $ev) {
                $events[] = kse_map_event($ev, 'musikheide');
            }
        }

        if ($input['use_seevetal'] && class_exists('SeevetalParser') && method_exists('SeevetalParser','crawl')) {
            $arr = SeevetalParser::crawl($input['seevetal_url'], (int)$input['seevetal_limit']);
            foreach ($arr as $ev) {
                $events[] = kse_map_event($ev, 'seevetal');
            }
        }

        // 2) Duplikat-Check & Status bestimmen
        foreach ($events as $ev) {
            $status = 'NEU';
            $reason = '';

            if (!$ev['title'] || !$ev['start']) {
                $status = 'FEHLER';
                $reason = 'Titel oder Start fehlen';
            } else {
                // a) TEC: existiert Event mit gleichem Start & gleichem Titel?
                $existing = kse_find_tec_by_title_and_start($ev['title'], $ev['start']);

                if ($existing) {
                    $status = 'SKIP';
                    $reason = 'Bestehendes TEC-Event gefunden (Titel+Start)';
                } else {
                    // b) Index (optionale, zukünftige Zuordnung)
                    $index = get_option('kse_import_index', []);
                    if (!empty($index[$ev['external_id']])) {
                        $status = 'UPDATE';
                        $reason = 'Im Index vorhanden (Quelle bekannt), aber kein passendes TEC-Event gefunden';
                    }
                }
            }

            $report['items'][] = [
                'source'      => $ev['source'],
                'external_id' => $ev['external_id'],
                'title'       => $ev['title'],
                'start'       => $ev['start'],
                'venue'       => $ev['venue'],
                'image'       => $ev['image'],
                'status'      => $status,
                'reason'      => $reason,
                'source_url'  => $ev['source_url'],
            ];
            $report['summary'][$status] = ($report['summary'][$status] ?? 0) + 1;
        }
    }

    if ($do_save && !empty($_POST['last_payload'])) {
        // abgesendetes, bereits gerendertes Report-JSON speichern
        $decoded = json_decode(stripslashes($_POST['last_payload']), true);
        if (is_array($decoded)) {
            update_option('kse_last_dry_run', $decoded, false);
            echo '<div class="notice notice-success"><p>Dry-Run-Protokoll gespeichert.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Konnte Protokoll nicht speichern.</p></div>';
        }
    }

    // Seite rendern
    ?>
    <div class="wrap">
        <h1>Dry Run → The Events Calendar</h1>

        <form method="post">
            <h2>Quellen</h2>
            <table class="form-table">
                <tr>
                    <th>Musik in alten Heidekirchen</th>
                    <td>
                        <label><input type="checkbox" name="use_musik" value="1" <?php checked(!empty($input['use_musik'])); ?>> aktiv</label><br>
                        Listen-URL:
                        <input type="url" name="musik_url" value="<?php echo esc_attr($input['musik_url']); ?>" class="regular-text code" style="width: 600px;">
                        &nbsp;Max:
                        <input type="number" name="musik_limit" min="1" max="50" value="<?php echo (int)$input['musik_limit']; ?>" style="width: 80px;">
                    </td>
                </tr>
                <tr>
                    <th>Seevetal Gemeinde</th>
                    <td>
                        <label><input type="checkbox" name="use_seevetal" value="1" <?php checked(!empty($input['use_seevetal'])); ?>> aktiv</label><br>
                        Listen-/Such-URL:
                        <input type="url" name="seevetal_url" value="<?php echo esc_attr($input['seevetal_url']); ?>" class="regular-text code" style="width: 600px;">
                        &nbsp;Max:
                        <input type="number" name="seevetal_limit" min="1" max="50" value="<?php echo (int)$input['seevetal_limit']; ?>" style="width: 80px;">
                        <?php if (!class_exists('SeevetalParser')): ?>
                            <p class="description">Hinweis: Kein <code>SeevetalParser</code> gefunden – Quelle wird ignoriert.</p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" name="kse_dry_run" class="button button-primary">Dry Run ausführen</button>
            </p>

            <?php if ($do_run): ?>
                <h2>Ergebnis (<?php echo count($report['items']); ?>)</h2>
                <p><strong>NEU:</strong> <?php echo (int)$report['summary']['NEU']; ?> &nbsp;
                   <strong>UPDATE:</strong> <?php echo (int)$report['summary']['UPDATE']; ?> &nbsp;
                   <strong>SKIP:</strong> <?php echo (int)$report['summary']['SKIP']; ?> &nbsp;
                   <strong>FEHLER:</strong> <?php echo (int)$report['summary']['FEHLER']; ?></p>

                <table class="widefat fixed striped">
                    <thead>
                    <tr>
                        <th>Quelle</th>
                        <th>Externe ID</th>
                        <th>Titel</th>
                        <th>Start</th>
                        <th>Ort</th>
                        <th>Status</th>
                        <th>Grund</th>
                        <th>Bild</th>
                        <th>Link</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($report['items'] as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['source']); ?></td>
                            <td><?php echo esc_html($row['external_id']); ?></td>
                            <td><?php echo esc_html($row['title']); ?></td>
                            <td><?php echo esc_html($row['start']); ?></td>
                            <td><?php echo esc_html($row['venue']); ?></td>
                            <td><strong><?php echo esc_html($row['status']); ?></strong></td>
                            <td><?php echo esc_html($row['reason']); ?></td>
                            <td><?php if (!empty($row['image'])): ?><img src="<?php echo esc_url($row['image']); ?>" style="max-width:70px;height:auto;border:1px solid #ddd"><?php endif; ?></td>
                            <td><a href="<?php echo esc_url($row['source_url']); ?>" target="_blank" rel="noopener">öffnen</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <input type="hidden" name="last_payload" value="<?php echo esc_attr(wp_json_encode($report)); ?>">
                    <button type="submit" name="kse_dry_run_save" class="button">Protokoll speichern</button>
                </p>
            <?php endif; ?>
        </form>
    </div>
    <?php
}

function kse_render_import_live() {
    // Letzten Dry-Run laden
    $report = get_option('kse_last_dry_run');
    if (empty($report['items'])) {
        echo '<div class="wrap"><h1>Import (LIVE)</h1><div class="notice notice-warning"><p>Kein Dry-Run gefunden. Bitte zuerst „Dry Run → The Events Calendar“ ausführen und speichern.</p></div></div>';
        return;
    }

    $do_import = isset($_POST['kse_do_import_live']);
    $log = [];
    $ok = $err = 0;

    if ($do_import) {
        foreach ($report['items'] as $row) {
            if (!in_array($row['status'], ['NEU','UPDATE'], true)) {
                continue; // SKIP/FEHLER nicht importieren
            }
            $res = kse_import_event_to_tec($row);
            if (is_wp_error($res)) {
                $log[] = 'FEHLER: '.$row['title'].' → '.$res->get_error_message();
                $err++;
            } else {
                $log[] = 'OK: '.$row['title'].' → Event #'.$res;
                $ok++;
            }
        }
        echo '<div class="notice notice-success"><p>Import abgeschlossen: '.$ok.' OK, '.$err.' Fehler.</p></div>';
    }

    ?>
    <div class="wrap">
        <h1>Import (LIVE) → The Events Calendar</h1>
        <p>Es werden nur Einträge mit Status <strong>NEU</strong> und <strong>UPDATE</strong> importiert.</p>
        <form method="post">
            <p class="submit">
                <button type="submit" name="kse_do_import_live" class="button button-primary">
                    Import starten
                </button>
            </p>
        </form>

        <?php if (!empty($log)): ?>
            <h2>Protokoll</h2>
            <pre style="max-height:320px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px;"><?php echo esc_html(implode("\n", $log)); ?></pre>
        <?php endif; ?>

        <h2>Vorschau (aus letztem Dry-Run)</h2>
        <table class="widefat striped">
            <thead><tr>
                <th>Quelle</th><th>Titel</th><th>Start</th><th>Ort</th><th>Status</th><th>Link</th>
            </tr></thead>
            <tbody>
            <?php foreach ($report['items'] as $r): if (!in_array($r['status'], ['NEU','UPDATE','SKIP','FEHLER'], true)) continue; ?>
                <tr>
                    <td><?php echo esc_html($r['source']); ?></td>
                    <td><?php echo esc_html($r['title']); ?></td>
                    <td><?php echo esc_html($r['start']); ?></td>
                    <td><?php echo esc_html($r['venue']); ?></td>
                    <td><strong><?php echo esc_html($r['status']); ?></strong></td>
                    <td><a href="<?php echo esc_url($r['source_url']); ?>" target="_blank" rel="noopener">öffnen</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

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

    if ($title === '') {
        return new WP_Error('kse_missing', 'Titel fehlt');
    }

    // Datum sicherstellen (MySQL)
    if (!preg_match('~^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$~', $start)) {
        [$start, $end] = kse_normalize_german_datetime_range($start, $end);
    }
    if ($start === '') {
        return new WP_Error('kse_missing', 'Startzeit unlesbar');
    }
    if ($end === '') $end = $start;

    // 0) Bestehendes Event suchen – erst external_id, dann (Titel+Start)
    $post_id = 0;
    if ($external_id !== '') {
        $post_id = kse_find_tec_by_external_id($external_id) ?: 0;
    }
    if (!$post_id) {
        $existing = kse_find_tec_by_title_and_start($title, $start);
        if ($existing) $post_id = (int)$existing->ID;
    }

    // 1) Anlegen/Updaten
    $tz = wp_timezone_string() ?: 'Europe/Berlin';
    $args_base = [
        'post_title'   => $title,
        'post_content' => $desc,
        'post_status'  => 'publish',
    ];

    if ($post_id) {
        $u = wp_update_post(array_merge($args_base, ['ID' => $post_id]), true);
        if (is_wp_error($u)) return $u;
    } else {
        if (function_exists('tribe_create_event')) {
            $post_id = tribe_create_event(array_merge($args_base, [
                'EventStartDate' => $start,
                'EventEndDate'   => $end,
                'EventTimezone'  => $tz,
            ]));
            if (is_wp_error($post_id) || !$post_id) {
                $post_id = wp_insert_post(array_merge($args_base, ['post_type' => 'tribe_events']), true);
            }
        } else {
            $post_id = wp_insert_post(array_merge($args_base, ['post_type' => 'tribe_events']), true);
        }
        if (is_wp_error($post_id) || !$post_id) {
            return new WP_Error('kse_insert_failed', 'Event konnte nicht angelegt werden');
        }
    }

    // 2) CPT/Metas erzwingen (unabhängig vom Zweig)
    if (get_post_type($post_id) !== 'tribe_events') {
        global $wpdb; $wpdb->update($wpdb->posts, ['post_type' => 'tribe_events'], ['ID' => $post_id]); clean_post_cache($post_id);
    }
    update_post_meta($post_id, '_EventStartDate',    $start);
    update_post_meta($post_id, '_EventEndDate',      $end);
    $s_utc = get_gmt_from_date($start, 'Y-m-d H:i:s');
    $e_utc = get_gmt_from_date($end,   'Y-m-d H:i:s');
    update_post_meta($post_id, '_EventStartDateUTC', $s_utc);
    update_post_meta($post_id, '_EventEndDateUTC',   $e_utc);
    update_post_meta($post_id, '_EventTimezone',     $tz);
    update_post_meta($post_id, '_EventDuration',     max(0, strtotime($e_utc) - strtotime($s_utc)));

    // 3) Venue
    if ($venue_name !== '') {
        $venue_id = kse_upsert_tribe_venue($venue_name, $venue_street, $venue_city ? ($venue_postcode.' '.$venue_city) : '');
        if ($venue_id) update_post_meta($post_id, '_EventVenueID', (int)$venue_id);
    }

    // 4) Bild
    if ($image !== '') {
        $att_id = kse_sideload_image($image, $post_id);
        if (!is_wp_error($att_id) && $att_id) set_post_thumbnail($post_id, $att_id);
    }

    // 5) Quelle/Index
    if ($source_url)   update_post_meta($post_id, '_kse_source_url', esc_url_raw($source_url));
    if ($external_id) {
        update_post_meta($post_id, '_kse_external_id', $external_id);
        $index = get_option('kse_import_index', []);
        $index[$external_id] = (int)$post_id;
        update_option('kse_import_index', $index, false);
    }

    error_log(sprintf('KSE Import: #%d "%s" → %s (%s)', $post_id, $title, $start, $tz));
    return (int)$post_id;
}

/** TEC-Event via externer ID finden */
function kse_find_tec_by_external_id(string $external_id): ?int {
    if ($external_id === '') return null;
    $q = new WP_Query([
        'post_type'      => 'tribe_events',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'meta_query'     => [[
            'key'   => '_kse_external_id',
            'value' => $external_id,
        ]],
        'fields' => 'ids',
    ]);
    if ($q->have_posts()) {
        return (int)$q->posts[0];
    }
    return null;
}


/** Wandelt WP-lokale Zeiten in TEC-kompatible Local+UTC um */
function kse_to_tec_dates(string $start_local, string $end_local, string $tz): array {
    try {
        $tz_obj = new DateTimeZone($tz);
    } catch (Exception $e) {
        $tz_obj = new DateTimeZone('Europe/Berlin');
    }
    $s = DateTime::createFromFormat('Y-m-d H:i:s', $start_local, $tz_obj) ?: new DateTime($start_local, $tz_obj);
    $e = DateTime::createFromFormat('Y-m-d H:i:s', $end_local,   $tz_obj) ?: new DateTime($end_local,   $tz_obj);

    $s_utc = clone $s; $s_utc->setTimezone(new DateTimeZone('UTC'));
    $e_utc = clone $e; $e_utc->setTimezone(new DateTimeZone('UTC'));

    return [
        'start_local' => $s->format('Y-m-d H:i:s'),
        'end_local'   => $e->format('Y-m-d H:i:s'),
        'start_utc'   => $s_utc->format('Y-m-d H:i:s'),
        'end_utc'     => $e_utc->format('Y-m-d H:i:s'),
    ];
}


/** Venue anlegen/finden */
function kse_upsert_tribe_venue(string $name, string $street = '', string $city = ''): int {
    $name = trim($name);
    if ($name === '') return 0;

    $q = new WP_Query([
        'post_type'      => 'tribe_venue',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'title'          => $name,
        'no_found_rows'  => true,
    ]);
    if ($q->have_posts()) {
        return (int)$q->posts[0]->ID;
    }

    $id = wp_insert_post([
        'post_type'   => 'tribe_venue',
        'post_title'  => $name,
        'post_status' => 'publish',
    ], true);
    if (is_wp_error($id) || !$id) return 0;

    if ($street) update_post_meta($id, '_VenueAddress', $street);
    if ($city)   update_post_meta($id, '_VenueCity',    $city);
    return (int)$id;
}

/** Bild von URL in Mediathek mit Duplikatprüfung (_kse_source_url am Attachment) */
function kse_sideload_image(string $url, int $attach_to_post = 0) {
    $url = esc_url_raw($url);
    if ($url === '') return 0;

    // Bereits vorhanden?
    $q = new WP_Query([
        'post_type'      => 'attachment',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'meta_query'     => [[
            'key'   => '_kse_source_url',
            'value' => $url,
        ]],
    ]);
    if ($q->have_posts()) {
        return (int)$q->posts[0]->ID;
    }

    // Download
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url);
    if (is_wp_error($tmp)) return $tmp;

    $file = [
        'name'     => wp_basename(parse_url($url, PHP_URL_PATH)),
        'type'     => mime_content_type($tmp),
        'tmp_name' => $tmp,
        'size'     => filesize($tmp),
        'error'    => 0,
    ];

    $id = media_handle_sideload($file, $attach_to_post);
    if (is_wp_error($id)) {
        @unlink($tmp);
        return $id;
    }
    update_post_meta($id, '_kse_source_url', $url);
    return (int)$id;
}


/* ================== Einstellungen ==================== */
function kse_render_settings() { ?>
    <div class="wrap">
        <h1>Einstellungen</h1>
        <p>(Platzhalter) Hier können später Suchworte, Cron-Jobs, Quellen-Mapping etc. gepflegt werden.</p>
    </div>
<?php }

/* ================== Hilfsfunktionen ================== */

function kse_map_event(array $ev, string $source): array
{
    // Rohwerte aus Parser
    $title = (string)($ev['title'] ?? '');
    $start = (string)($ev['start'] ?? ($ev['start_raw'] ?? ''));
    $end   = (string)($ev['end']   ?? ($ev['end_raw'] ?? ''));
    $desc  = (string)($ev['description'] ?? ($ev['desc'] ?? ''));
    $image = (string)($ev['image'] ?? '');
    $url   = (string)($ev['url']   ?? ($ev['source'] ?? $ev['source_url'] ?? ''));
    $venue_raw = (string)($ev['venue'] ?? ($ev['ort'] ?? ''));
    $venue_name = (string)($ev['venue_name'] ?? '');
    $venue_street = (string)($ev['venue_street'] ?? '');
    $venue_postcode = (string)($ev['venue_postcode'] ?? '');
    $venue_city = (string)($ev['venue_city'] ?? '');

    // Externe ID aus URL ableiten
    $external_id = '';
    if ($source === 'musikheide') {
        if (preg_match('~/termine/([0-9a-f-]{10,})~i', $url, $m)) {
            $external_id = 'musikheide:' . strtolower($m[1]);
        }
    } elseif ($source === 'seevetal') {
        if (preg_match('~-(\d{6,}-\d{5})\.html$~', $url, $m)) {
            $external_id = 'seevetal:' . $m[1];
        }
    }
    if ($external_id === '') {
        $external_id = $source . ':' . md5($url . '|' . $title);
    }

    // Datum normalisieren (de→MySQL), inkl. Bereich "… - …"
    [$start_local, $end_local] = kse_normalize_german_datetime_range($start, $end);

    // Adresse zerlegen, wenn Parser es noch nicht tat
    if ($venue_name === '' && $venue_street === '' && ($venue_postcode === '' || $venue_city === '')) {
        [$venue_name, $venue_street, $venue_postcode, $venue_city] = kse_parse_address_fields(
            $venue_name !== '' ? ($venue_name . "\n" . $venue_raw) : $venue_raw
        );
    }

    return [
        'source'         => $source,
        'external_id'    => $external_id,
        'title'          => $title,
        'description'    => $desc,
        'start'          => $start_local,     // Y-m-d H:i:s
        'end'            => $end_local,       // Y-m-d H:i:s
        'venue'          => $venue_name ?: $venue_raw,
        'venue_name'     => $venue_name,
        'venue_address'  => $venue_street,
        'venue_postcode' => $venue_postcode,
        'venue_city'     => $venue_city,
        'image'          => $image,
        'source_url'     => $url,
    ];
}

/** "Do., 04.09.2025, 17:30 Uhr" (oder Bereiche "… - …") → ['start','end'] in Y-m-d H:i:s */
function kse_normalize_german_datetime_range(string $start_raw, string $end_raw = ''): array {
    $to_mysql = function (string $s): string {
        $s = trim($s);
        // entferne Wochentag, "Uhr", Kommas
        $s = preg_replace('~(?:Mo|Di|Mi|Do|Fr|Sa|So)\.,?\s*~u', '', $s);
        $s = str_replace('Uhr', '', $s);
        $s = trim(str_replace(',', '', $s));
        // Variante "dd.mm.yyyy HH:MM"
        if (preg_match('~(\d{2})\.(\d{2})\.(\d{4})\s+(\d{1,2}):(\d{2})~', $s, $m)) {
            return sprintf('%04d-%02d-%02d %02d:%02d:00', $m[3], $m[2], $m[1], $m[4], $m[5]);
        }
        // Nur Datum
        if (preg_match('~(\d{2})\.(\d{2})\.(\d{4})~', $s, $m)) {
            return sprintf('%04d-%02d-%02d 00:00:00', $m[3], $m[2], $m[1]);
        }
        // Fallback: unverändert (verhindert "heute")
        return $s;
    };

    // Fälle wie "Fr., 29.08.2025, 16:00 Uhr - So., 31.08.2025, 19:00 Uhr"
    if (strpos($start_raw, ' - ') !== false && $end_raw === '') {
        [$lhs, $rhs] = array_map('trim', explode(' - ', $start_raw, 2));
        return [$to_mysql($lhs), $to_mysql($rhs)];
    }
    $start = $to_mysql($start_raw);
    $end   = $end_raw !== '' ? $to_mysql($end_raw) : $start;
    return [$start, $end];
}

/** Heuristische Adress-Zerlegung aus Freitext → [name, street, postcode, city] */
function kse_parse_address_fields(string $txt): array {
    $txt = trim(preg_replace('~\s+~', ' ', str_replace(["\r", "\n"], ' ', $txt)));

    // PLZ + Ort
    $postcode = ''; $city = '';
    if (preg_match('~\b(\d{5})\s+([A-Za-zÄÖÜäöüß\-\.\' ]{2,})\b~u', $txt, $m)) {
        $postcode = $m[1];
        $city     = trim($m[2]);
        // bis zur PLZ alles davor betrachten
        $before = trim(substr($txt, 0, strpos($txt, $m[0])));
    } else {
        $before = $txt;
    }

    // Straße + Nr (letzter Teil vor PLZ/Ort)
    $street = '';
    if (preg_match('~([A-Za-zÄÖÜäöüß\-\.\' ]+)\s+(\d+[a-zA-Z\-]?)$~u', $before, $m2)) {
        $street = trim($m2[0]);
        $name   = trim(substr($before, 0, -strlen($m2[0])));
    } else {
        $name = $before;
    }

    $name = trim(trim($name), ',');
    return [$name, trim($street), $postcode, $city];
}


/** Duplikat-Suche in The Events Calendar: Titel + _EventStartDate */
function kse_find_tec_by_title_and_start(string $title, string $start): ?WP_Post
{
    if (!post_type_exists('tribe_events')) {
        // TEC (noch) nicht aktiv – behandle wie „nicht vorhanden“
        return null;
    }

    // Suche Events mit gleichem Start
    $q = new WP_Query([
        'post_type'      => 'tribe_events',
        'post_status'    => 'any',
        'posts_per_page' => 50,
        'no_found_rows'  => true,
        'meta_query'     => [
            [
                'key'     => '_EventStartDate',
                'value'   => $start,           // Format: Y-m-d H:i:s (lokale TZ)
                'compare' => '='
            ]
        ]
    ]);

    if (!$q->have_posts()) return null;

    $needle = kse_norm_title($title);
    foreach ($q->posts as $p) {
        $pt = kse_norm_title($p->post_title ?? '');
        if ($pt === $needle) {
            return $p; // exakte Norm-Übereinstimmung
        }
    }
    return null;
}

function kse_norm_title(string $t): string
{
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = mb_strtolower(trim(preg_replace('~\s+~u', ' ', $t)));
    return $t;
}

    // 1) Alte/konfliktträchtige Menüs ausblenden (sehr spätes Timing)
    add_action('admin_menu', function () {
        // Trage hier ggf. den exakten Legacy-Slug aus deiner Seevetal-Klasse ein:
        $legacy_slugs = [
            'seevetal-export',       // häufig
            'kse_event_export',      // möglich
            'Veranstaltungsquellen',       // Page title
            'Event Export',                 // Menu title
        ];
        foreach ($legacy_slugs as $slug) {
            remove_menu_page($slug);
        }
    }, 9999);
