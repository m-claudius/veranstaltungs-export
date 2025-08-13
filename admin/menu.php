<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Zentrales Admin-Menü für den Plugin-Bereich „Event Import“
 * Submenüs:
 *  - Musik in alten Heidekirchen  (nutzt kse_render_musikheide_parser_admin)
 *  - Seevetal Gemeinde            (Bridge, ruft bestehende Seevetal-Ansicht oder verlinkt dorthin)
 *  - Einstellungen                (Tabs: Statistik, Cron Job)
 */

add_action('admin_menu', 'kse_register_event_import_menu');

function kse_register_event_import_menu() {

    // Top-Level
    $parent_slug = 'kse-event-import';
    add_menu_page(
        'Event Import',
        'Event Import',
        'manage_options',
        $parent_slug,
        'kse_render_event_import_welcome',
        'dashicons-calendar-alt',
        60
    );

    // 1) Musik in alten Heidekirchen
    add_submenu_page(
        $parent_slug,
        'Musik in alten Heidekirchen',
        'Musik in alten Heidekirchen',
        'manage_options',
        'kse-musikheide-parser',
        'kse_render_musikheide_parser_admin'
    );

    // 2) Seevetal Gemeinde (Bridge)
    add_submenu_page(
        $parent_slug,
        'Seevetal Gemeinde',
        'Seevetal Gemeinde',
        'manage_options',
        'kse-seevetal',
        'kse_render_seevetal_admin_bridge'
    );

    // 3) Einstellungen (enthält Tabs für Statistik & Cron)
    add_submenu_page(
        $parent_slug,
        'Einstellungen',
        'Einstellungen',
        'manage_options',
        'kse-settings',
        'kse_render_settings'
    );
}

/* ========= Seiten-Callbacks ========= */

function kse_render_event_import_welcome() {
    ?>
    <div class="wrap">
        <h1>Event Import</h1>
        <p>Wähle links einen Importer aus. Aktuell verfügbar:</p>
        <ul style="list-style:disc;margin-left:20px;">
            <li><a href="<?php echo esc_url( admin_url('admin.php?page=kse-musikheide-parser') ); ?>">Musik in alten Heidekirchen</a></li>
            <li><a href="<?php echo esc_url( admin_url('admin.php?page=kse-seevetal') ); ?>">Seevetal Gemeinde</a></li>
            <li><a href="<?php echo esc_url( admin_url('admin.php?page=kse-settings') ); ?>">Einstellungen</a></li>
        </ul>
    </div>
    <?php
}

/**
 * Musik-in-alten-Heidekirchen – Parser UI
 * (Die Funktion kse_render_musikheide_parser_admin kommt aus deiner Parser-Datei.)
 * Falls sie nicht existiert, zeigen wir einen Hinweis.
 */
if (!function_exists('kse_render_musikheide_parser_admin')) {
    function kse_render_musikheide_parser_admin() {
        ?>
        <div class="wrap">
            <h1>Musik in alten Heidekirchen – Parser</h1>
            <p><em>Der Parser ist nicht geladen. Bitte sicherstellen, dass
                <code>crawler/MusikInAltenHeidekirchenParser.php</code> eingebunden wird.</em></p>
        </div>
        <?php
    }
}

/**
 * Seevetal-Bridge:
 * - Wenn es eine Klasse SeevetalExporter mit Render-Methode gibt, benutzen wir diese.
 * - Sonst zeigen wir einen Link auf die alte Seite (falls sie noch registriert ist).
 */
function kse_render_seevetal_admin_bridge() {
    echo '<div class="wrap"><h1>Seevetal Gemeinde</h1>';

    // 1) Existiert ein Objekt, das selbst rendern kann?
    if (class_exists('SeevetalExporter')) {
        $obj = new SeevetalExporter();

        // Häufige/naheliegende Render-Methodennamen:
        foreach (['render_admin_page','render','output_admin_page','admin_page'] as $m) {
            if (method_exists($obj, $m)) {
                $obj->{$m}();
                echo '</div>';
                return;
            }
        }
    }

    // 2) Fallback: freundlicher Hinweis + Links
    $maybe_old_slugs = [
        'seevetal-event-export',
        'seevetal-export',
        'veranstaltungsquellen',
        'event-export',
    ];

    $links = [];
    foreach ($maybe_old_slugs as $slug) {
        $links[] = '<li><a href="' . esc_url( admin_url('admin.php?page='.$slug) ) . '">Versuch: '.$slug.'</a></li>';
    }

    echo '<p>Die bestehende Seevetal-Ansicht wurde nicht automatisch gefunden.
          Wenn du noch die alte Seite nutzt, probiere einen der folgenden Links:</p>';
    echo '<ul style="list-style:disc;margin-left:20px;">' . implode('', $links) . '</ul>';
    echo '<p>Tipp: Am besten die alte Menü-Registrierung deaktivieren und
          die Seevetal-Ausgabe als eigene Render-Methode in <code>SeevetalExporter</code> bereitstellen
          (z. B. <code>render_admin_page()</code>). Dann ruft diese Bridge sie direkt auf.</p>';
    echo '</div>';
}

/**
 * Einstellungen – Tabs: Statistik & Cron Job
 * (schlanke Platzhalter, du kannst sie später füllen)
 */
function kse_render_settings() {
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'statistik';
    $base = admin_url('admin.php?page=kse-settings');

    ?>
    <div class="wrap">
        <h1>Einstellungen</h1>
        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url($base.'&tab=statistik'); ?>"
               class="nav-tab <?php echo $tab==='statistik'?'nav-tab-active':''; ?>">Statistik</a>
            <a href="<?php echo esc_url($base.'&tab=cron'); ?>"
               class="nav-tab <?php echo $tab==='cron'?'nav-tab-active':''; ?>">Cron Job</a>
        </h2>

        <?php if ($tab === 'cron'): ?>
            <h2>Cron Job</h2>
            <p>Hier kannst du später einen automatischen Import einrichten (WP-Cron).
               Aktuell nur Platzhalter.</p>
            <ul>
                <li>WP-Cron aktiv: <?php echo wp_next_scheduled('kse_dummy_event') ? 'ja' : 'nein'; ?></li>
                <li>Nächster Termin (dummy): <?php
                    $ts = wp_next_scheduled('kse_dummy_event');
                    echo $ts ? esc_html( date_i18n('Y-m-d H:i:s', $ts) ) : '—';
                ?></li>
            </ul>
        <?php else: ?>
            <h2>Statistik</h2>
            <p>Platzhalter für Import-Zähler, letzte Laufzeit, letzte Fehler etc.</p>
            <ul>
                <li>Letzter Import: —</li>
                <li>Importierte Events (heute): —</li>
                <li>Fehler (heute): —</li>
            </ul>
        <?php endif; ?>
    </div>
    <?php
}
