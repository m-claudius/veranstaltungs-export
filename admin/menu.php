<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin-Menü „Event Import“ + Unterseiten
 * - Musik in alten Heidekirchen – Parser
 * - Gemeinde Seevetal – Parser
 * - Einstellungen (ruft kse_render_settings_admin aus admin/settings.php)
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
        'kse_render_settings_admin' // kommt aus deiner vorhandenen settings.php
    );

});

/* ---------------- Landing ---------------- */

function kse_render_event_import_landing() {
    echo '<div class="wrap"><h1>Event Import</h1>
        <p>Wähle links eine Quelle:</p>
        <ul style="list-style:disc;margin-left:20px">
          <li><a href="'.esc_url(admin_url('admin.php?page=kse-miah')).'">Musik in alten Heidekirchen</a></li>
          <li><a href="'.esc_url(admin_url('admin.php?page=kse-seevetal')).'">Gemeinde Seevetal</a></li>
          <li><a href="'.esc_url(admin_url('admin.php?page=kse-settings')).'">Einstellungen</a></li>
        </ul>
    </div>';
}

/* -------------- Hilfsfunktionen UI -------------- */

function kse_trim_text($text, $len = 180) {
    $t = trim(preg_replace('/\s+/u',' ', wp_strip_all_tags((string)$text)));
    if (mb_strlen($t) <= $len) return esc_html($t);
    return esc_html(mb_substr($t, 0, $len)).'…';
}

function kse_render_events_table(array $events) {
    echo '<table class="widefat striped"><thead><tr>
            <th style="width:32%">Titel</th>
            <th style="width:14%">Start</th>
            <th style="width:22%">Ort</th>
            <th style="width:12%">Bild</th>
            <th>Beschreibung</th>
          </tr></thead><tbody>';

    foreach ($events as $ev) {
        $title = esc_html($ev['title'] ?? '');
        $start = esc_html($ev['start'] ?? '');
        $loc   = esc_html($ev['location'] ?? '');
        $src   = esc_url($ev['source_url'] ?? '#');
        $img   = '';
        if (!empty($ev['image'])) {
            $img = '<img src="'.esc_url($ev['image']).'" alt="" style="max-width:120px;height:auto;border-radius:4px" />';
        }
        $desc = kse_trim_text($ev['description'] ?? '');

        echo '<tr>
            <td>'.($src ? '<a href="'.$src.'" target="_blank" rel="noopener">'.$title.'</a>' : $title).'</td>
            <td>'.$start.'</td>
            <td>'.$loc.'</td>
            <td>'.$img.'</td>
            <td>'.$desc.'</td>
        </tr>';
    }

    echo '</tbody></table>';
}

/* -------------- Musik in alten Heidekirchen -------------- */

function kse_render_miah_admin() {
    echo '<div class="wrap"><h1>Musik in alten Heidekirchen – Parser</h1>';

    if (!class_exists('MusikInAltenHeidekirchenParser')) {
        echo '<div class="notice notice-error"><p>Der Parser ist nicht geladen. Bitte sicherstellen, dass <code>crawler/MusikInAltenHeidekirchenParser.php</code> in <code>veranstaltungs-export.php</code> eingebunden wird.</p></div></div>';
        return;
    }

    // Wichtig: Instanz (kein statischer Aufruf!)
    $parser = new MusikInAltenHeidekirchenParser();

    // Liste + Details holen
    $list_url = defined('MusikInAltenHeidekirchenParser::LIST_URL')
        ? MusikInAltenHeidekirchenParser::LIST_URL
        : 'https://musik-in-alten-heidekirchen.wir-e.de/termine';

    $events = $parser->crawl($list_url, 30);

    echo '<p><strong>Listen-URL:</strong> <a href="'.esc_url($list_url).'" target="_blank" rel="noopener">'.esc_html($list_url).'</a><br>';
    echo '<strong>Gefundene Events:</strong> '.count($events).'</p>';

    if (empty($events)) {
        echo '<p>Keine Veranstaltungen gefunden. Prüfe, ob die Seite Termine listet oder ob sich die Struktur geändert hat.</p></div>';
        return;
    }

    kse_render_events_table($events);
    echo '</div>';
}

/* -------------- Gemeinde Seevetal -------------- */

function kse_render_seevetal_admin() {
    echo '<div class="wrap"><h1>Gemeinde Seevetal – Parser</h1>';

    if (!class_exists('SeevetalParser')) {
        echo '<div class="notice notice-warning"><p>Parser <code>SeevetalParser</code> nicht gefunden. Stelle sicher, dass <code>crawler/SeevetalParser.php</code> eingebunden ist.</p></div></div>';
        return;
    }

    // Whitelist/Blacklist aus Einstellungen (falls vorhanden)
    $opts = get_option('kse_options', []);
    $wl = array_filter(array_map('trim', explode(',', $opts['seevetal_whitelist'] ?? 'Musik,Kultur')));
    $bl = array_filter(array_map('trim', explode(',', $opts['seevetal_blacklist'] ?? '')));

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

    // Blacklist grob auf URL anwenden
    if ($bl) {
        $links = array_values(array_filter($links, function ($u) use ($bl) {
            foreach ($bl as $b) {
                if ($b !== '' && stripos($u, $b) !== false) return false;
            }
            return true;
        }));
    }

    echo '<h2>Gefundene Detail-Links</h2>';
    if (!$links) {
        echo '<p>Keine Links gefunden.</p></div>';
        return;
    }
    echo '<ol>';
    foreach ($links as $u) {
        echo '<li><a href="'.esc_url($u).'" target="_blank" rel="noopener">'.esc_html($u).'</a></li>';
    }
    echo '</ol>';

    // Probeauswertung (erste 10)
    echo '<h2>Probeauswertung</h2>';
    $probe = [];
    foreach (array_slice($links, 0, 10) as $u) {
        $ev = $parser->get_cached_detail($u);
        if (!$ev) continue;
        // Normalisieren auf unsere Tabellenspalten
        $probe[] = [
            'title'       => $ev['title']     ?? '',
            'start'       => $ev['datetime']  ?? '',
            'location'    => $ev['location']  ?? '',
            'image'       => $ev['image']     ?? '',
            'description' => $ev['desc']      ?? ($ev['description'] ?? ''),
            'source_url'  => $u,
        ];
    }

    if ($probe) {
        kse_render_events_table($probe);
    } else {
        echo '<p>Keine Details auswertbar (erste 10). Prüfe Parser/Struktur.</p>';
    }

    echo '</div>';
}
