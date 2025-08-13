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

    // Seevetal (Platzhalter, bis deine UI vorhanden ist)
    add_submenu_page(
        'kse_event_import',
        'Seevetal Gemeinde',
        'Seevetal Gemeinde',
        'manage_options',
        'kse_seevetal',
        'kse_render_seevetal_placeholder'
    );

    // DRY RUN
    add_submenu_page(
        'kse_event_import',
        'Dry Run → The Events Calendar',
        'Dry Run → The Events Calendar',
        'manage_options',
        'kse_dry_run',
        'kse_render_dry_run'
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

/* ================== Einstellungen ==================== */
function kse_render_settings() { ?>
    <div class="wrap">
        <h1>Einstellungen</h1>
        <p>(Platzhalter) Hier können später Suchworte, Cron-Jobs, Quellen-Mapping etc. gepflegt werden.</p>
    </div>
<?php }

/* ================== Hilfsfunktionen ================== */

/** Vereinheitlichtes Event-Array + externe ID aus Quelle extrahieren */
function kse_map_event(array $ev, string $source): array
{
    $title = $ev['title']       ?? '';
    $start = $ev['start']       ?? '';
    $venue = $ev['venue']       ?? ($ev['ort'] ?? '');
    $image = $ev['image']       ?? '';
    $url   = $ev['url']         ?? ($ev['source'] ?? $ev['source_url'] ?? '');

    $external_id = '';
    if ($source === 'musikheide') {
        // /termine/<uuid>
        if (preg_match('~/termine/([0-9a-f-]{10,})~i', (string)$url, $m)) {
            $external_id = 'musikheide:' . strtolower($m[1]);
        }
    } elseif ($source === 'seevetal') {
        // ...-910027150-20200.html → 910027150-20200
        if (preg_match('~-(\d{6,}-\d{5})\.html$~', (string)$url, $m)) {
            $external_id = 'seevetal:' . $m[1];
        }
    }
    if ($external_id === '') {
        $external_id = $source . ':' . md5((string)$url . '|' . $title . '|' . $start);
    }

    return [
        'source'      => $source,
        'external_id' => $external_id,
        'title'       => (string)$title,
        'start'       => (string)$start,
        'venue'       => (string)$venue,
        'image'       => (string)$image,
        'source_url'  => (string)$url,
    ];
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
