<?php
/**
 * Admin-Menü & Seiten – Veranstaltungs-Export
 * Neu geschrieben (Empore unlimitiert, Kulturverein Winsen Vorschau + Run-Now)
 */
if (!defined('ABSPATH')) exit;

/* =========================================================
 * Helpers
 * =======================================================*/
if (!function_exists('kse_html')) {
    function kse_html($s){ return esc_html((string)$s); }
}
if (!function_exists('kse_array_get')) {
    function kse_array_get($arr, $key, $default=''){
        return (is_array($arr) && array_key_exists($key, $arr)) ? $arr[$key] : $default;
    }
}
if (!function_exists('kse_wp_notice')) {
    function kse_wp_notice($msg, $class='info'){
        printf('<div class="notice notice-%s"><p>%s</p></div>', kse_html($class), wp_kses_post($msg));
    }
}
if (!function_exists('kse_render_run_now_link')) {
    /**
     * Link-Button, der den Cron-Runner sofort ausführt (benötigt admin_post Handler kse_run_source)
     */
    function kse_render_run_now_link($src, $label = 'Crawler jetzt starten') {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=kse_run_source&src=' . sanitize_key($src)),
            'kse_run_source'
        );
        echo '<a href="' . esc_url($url) . '" class="button" style="margin-left:8px">' . esc_html($label) . '</a>';
    }
}
if (!function_exists('kse_table_styles_inline')) {
    function kse_table_styles_inline(){
        echo '<style>
            .kse-table{width:100%;border-collapse:collapse;margin-top:12px}
            .kse-table th,.kse-table td{border:1px solid #ddd;padding:8px;vertical-align:top}
            .kse-table th{background:#f6f7f7;text-align:left}
            .kse-img{max-width:160px;height:auto;display:block}
            .kse-desc{max-width:700px;word-wrap:break-word;white-space:normal}
        </style>';
    }
}

/* =========================================================
 * Admin-Menü registrieren
 * =======================================================*/
add_action('admin_menu', 'kse_register_menu');
function kse_register_menu(){
    $cap = 'manage_options';

    add_menu_page(
        'Veranstaltungs-Export', 'Veranstaltungs-Export',
        $cap, 'kse-dashboard', 'kse_render_dashboard',
        'dashicons-calendar-alt', 56
    );

    // Bestehende Quellen
    add_submenu_page('kse-dashboard','Gemeinde Seevetal','Gemeinde Seevetal',$cap,'kse-seevetal','kse_render_seevetal');
    add_submenu_page('kse-dashboard','Empore Buchholz','Empore Buchholz',$cap,'kse-empore','kse_render_empore');
    
    // NEU: Burg Seevetal
    add_submenu_page('kse-dashboard','Burg Seevetal','Burg Seevetal',$cap,'kse-burg','kse_render_burg');

    // MIAH (Musik in alten Heidekirchen)
    add_submenu_page('kse-dashboard','Musik in alten Heidekirchen','Musik in alten Heidekirchen',$cap,'kse-miah','kse_render_miah');

    // NEU: Kulturverein Winsen
    add_submenu_page('kse-dashboard','Kulturverein Winsen','Kulturverein Winsen',$cap,'kse-winsen','kse_render_winsen');

    // Einstellungen (falls vorhanden)
    if (function_exists('kse_settings_render_page')){
        add_submenu_page('kse-dashboard', 'Einstellungen', 'Einstellungen', $cap, 'kse-settings', 'kse_settings_render_page');
    }
    // Statistik (falls vorhanden)
    if (function_exists('kse_render_stats_admin')){
        add_submenu_page('kse-dashboard', 'Statistik', 'Statistik', $cap, 'kse-stats', 'kse_render_stats_admin');
    }
    // Dubletten-Diagnose & Bereinigung
    if (function_exists('kse_render_dubletten')){
        add_submenu_page('kse-dashboard', 'Dubletten', 'Dubletten', $cap, 'kse-dubletten', 'kse_render_dubletten');
    }
}

/* =========================================================
 * Dashboard (sanfter Fallback)
 * =======================================================*/
if (!function_exists('kse_render_dashboard')) {
function kse_render_dashboard(){
    echo '<div class="wrap"><h1>Veranstaltungs-Export</h1>';
    echo '<p>Wähle eine Quelle im Menü links (z. B. „Empore Buchholz“ oder „Kulturverein Winsen“), um die Liste zu aktualisieren oder den Crawler zu starten.</p>';
    echo '</div>';
}}
/* =========================================================
 * Gemeinde Seevetal – nur Platzhalter, um vorhandene Render-Funktion zu nutzen
 * =======================================================*/
if (!function_exists('kse_render_seevetal')) {
function kse_render_seevetal(){
    echo '<div class="wrap"><h1>Gemeinde Seevetal</h1>';
    kse_wp_notice('Diese Seite nutzt die bestehende Implementierung nicht. Bitte belasse deine vorhandene Datei/Funktion – oder sag mir Bescheid, wenn ich sie hier nachbauen soll.','warning');
    echo '</div>';
}}
/* =========================================================
 * MIAH – Platzhalter/Fallback
 * =======================================================*/
if (!function_exists('kse_render_miah')) {
function kse_render_miah(){
    echo '<div class="wrap"><h1>Musik in alten Heidekirchen</h1>';
    kse_wp_notice('Diese Seite nutzt die bestehende Implementierung nicht. Bitte belasse deine vorhandene Datei/Funktion – oder sag mir Bescheid, wenn ich sie hier nachbauen soll.','warning');
    echo '</div>';
}}

/* =========================================================
 * EMPLORE BUCHHOLZ – Vorschau unlimitiert + Run-Now
 * =======================================================*/
if (!function_exists('kse_render_empore')) {
function kse_render_empore(){
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

    $do_crawl = isset($_GET['crawl']);
    $events   = [];
    echo '<div class="wrap"><h1>Empore Buchholz</h1>';

    // Buttons
    $refresh_url = esc_url(add_query_arg(['crawl'=>1]));
    echo '<p>';
    echo '<a class="button button-primary" href="'.$refresh_url.'">Liste aktualisieren</a>';
    if (function_exists('kse_render_run_now_link')) {
        kse_render_run_now_link('empore');
    }
    echo '</p>';

    // Crawl-Vorschau (UNBEGRENZT)
    if ($do_crawl) {
        if (!class_exists('EmporeBuchholzParser')) {
            kse_wp_notice('Parser-Klasse <code>EmporeBuchholzParser</code> wurde nicht gefunden. Bitte sicherstellen, dass die Datei geladen wird (require_once).', 'error');
        } else {
            try {
                $events = (array) EmporeBuchholzParser::crawl(['limit' => 0]); // 0 = unlimitiert
            } catch (Throwable $e) {
                kse_wp_notice('Fehler beim Crawl: '.kse_html($e->getMessage()), 'error');
            }
        }
    }

    $count = is_array($events) ? count($events) : 0;
    echo '<p><em>Gefunden: '.intval($count).'</em></p>';

    // Tabelle
    if ($count > 0) {
        kse_table_styles_inline();
        echo '<table class="kse-table">';
        echo '<thead><tr>
                <th>#</th>
                <th>Titel</th>
                <th>Datum/Zeit</th>
                <th>Ort</th>
                <th>Bild</th>
                <th>Quelle</th>
                <th>Beschreibung (Auszug)</th>
              </tr></thead><tbody>';
        $i=0;
        foreach ($events as $ev) {
            $i++;
            $title = wp_strip_all_tags(kse_array_get($ev,'title',''));
            $start = kse_array_get($ev,'start', kse_array_get($ev,'datetime',''));
            $loc   = kse_array_get($ev,'location','');
            $img   = kse_array_get($ev,'image','');
            $src   = kse_array_get($ev,'source_url','');
            $desc  = wp_strip_all_tags(kse_array_get($ev,'description',''));
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($desc) > 300) $desc = mb_substr($desc, 0, 300).'…';
            } else {
                if (strlen($desc) > 300) $desc = substr($desc, 0, 300).'…';
            }

            echo '<tr>';
            echo '<td>'.intval($i).'</td>';
            echo '<td>'.kse_html($title).'</td>';
            echo '<td>'.kse_html($start).'</td>';
            echo '<td>'.kse_html($loc).'</td>';
            echo '<td>'.($img ? '<img class="kse-img" src="'.esc_url($img).'" alt="" />' : '&nbsp;').'</td>';
            echo '<td>'.($src ? '<a href="'.esc_url($src).'" target="_blank" rel="noopener">öffnen</a>' : '&nbsp;').'</td>';
            echo '<td class="kse-desc">'.kse_html($desc).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        if ($do_crawl) {
            echo '<p>Keine Events gefunden.</p>';
        } else {
            echo '<p>Klicke auf „Liste aktualisieren“, um die Vorschau zu laden.</p>';
        }
    }

    echo '</div>';
}}
/* =========================================================
 * KULTURVEREIN WINSEN – Vorschau unlimitiert, Debug-Scan + Run-Now
 * =======================================================*/
if (!function_exists('kse_render_winsen')) {
function kse_render_winsen(){
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

    $list_url  = 'https://www.kv-winsen.de/programm/index.html';
    $do_crawl  = isset($_GET['crawl']);
    $debug     = isset($_GET['debug']);
    $events    = [];
    $links     = [];
    $notes     = [];

    echo '<div class="wrap"><h1>Kulturverein Winsen</h1>';

    // Buttons
    $refresh_url = esc_url(add_query_arg(['crawl'=>1]));
    $debug_url   = esc_url(add_query_arg(['crawl'=>1,'debug'=>1]));
    echo '<p>';
    echo '<a class="button button-primary" href="'.$refresh_url.'">Liste aktualisieren</a> ';
    if (function_exists('kse_render_run_now_link')) {
        kse_render_run_now_link('winsen');
    }
    echo ' <a class="button" href="'.$debug_url.'">Debug-Scan</a>';
    echo '</p>';

    // Debug-Scan (HTTP + Link-Erkennung)
    if ($do_crawl && $debug) {
        $res  = wp_remote_get($list_url, ['timeout'=>20,'redirection'=>5,'headers'=>['User-Agent'=>'VE-Winsen/1.0']]);
        if (is_wp_error($res)) {
            $notes[] = 'HTTP-Fehler beim Laden der Programm-Seite: '.$res->get_error_message();
        } else {
            $code = wp_remote_retrieve_response_code($res);
            $body = wp_remote_retrieve_body($res);
            $len  = is_string($body) ? strlen($body) : 0;
            $notes[] = "Programm-Seite geladen: HTTP $code, Bytes $len";

            if (class_exists('KulturvereinWinsenParser')) {
                try {
                    $links = KulturvereinWinsenParser::collect_detail_links($list_url);
                    $notes[] = 'Gefundene Detail-Links (Reservix): '.count($links);
                    if ($do_crawl && $debug && !empty($links) && class_exists('KulturvereinWinsenParser')) {
                        $testLinks = array_slice($links, 0, 3);
                        foreach ($testLinks as $lu) {
                            $body = KulturvereinWinsenParser::http_get_body($lu, ['referer' => $list_url]);
                            $notes[] = 'Detail-Check: ' . $lu . ' → ' . ($body !== '' ? 'OK' : 'BLOCKED/EMPTY');
                        }

                    }

                } catch (Throwable $e) {
                    $notes[] = 'collect_detail_links() Exception: '.$e->getMessage();
                }
            } else {
                $notes[] = 'Parser-Klasse KulturvereinWinsenParser nicht gefunden.';
            }
        }
    }

    // Crawl-Vorschau (UNBEGRENZT)
    if ($do_crawl) {
        if (!class_exists('KulturvereinWinsenParser')) {
            kse_wp_notice('Parser-Klasse <code>KulturvereinWinsenParser</code> wurde nicht gefunden. Bitte sicherstellen, dass die Datei geladen wird (require_once).', 'error');
        } else {
            try {
                $events = (array) KulturvereinWinsenParser::crawl(['limit' => 0]);
            } catch (Throwable $e) {
                kse_wp_notice('Fehler beim Crawl: '.kse_html($e->getMessage()), 'error');
            }
        }
    }

    // Hinweise ausgeben
    if (!empty($notes)) {
        echo '<div class="notice notice-info"><p>'.implode('<br/>', array_map('esc_html', $notes)).'</p></div>';
    }

    $count = is_array($events) ? count($events) : 0;
    echo '<p><em>Gefunden: '.intval($count).'</em></p>';

    // Links-Liste im Debug-Mode
    if ($do_crawl && $debug && !empty($links)) {
        echo '<h2>Gefundene Detail-Links (Top 20)</h2><ol>';
        $i=0;
        foreach ($links as $u) {
            echo '<li><a href="'.esc_url($u).'" target="_blank" rel="noopener">'.esc_html($u).'</a></li>';
            if (++$i>=20) break;
        }
        echo '</ol>';
    }

    // Tabelle
    if ($count > 0) {
        kse_table_styles_inline();
        echo '<table class="kse-table">';
        echo '<thead><tr>
                <th>#</th>
                <th>Titel</th>
                <th>Datum/Zeit</th>
                <th>Ort</th>
                <th>Bild</th>
                <th>Quelle</th>
                <th>Beschreibung (Auszug)</th>
              </tr></thead><tbody>';
        $i=0;
        foreach ($events as $ev) {
            $i++;
            $title = wp_strip_all_tags(kse_array_get($ev,'title',''));
            $start = kse_array_get($ev,'start', kse_array_get($ev,'datetime',''));
            $loc   = kse_array_get($ev,'location','');
            $img   = kse_array_get($ev,'image','');
            $src   = kse_array_get($ev,'source_url','');
            $desc  = wp_strip_all_tags(kse_array_get($ev,'description',''));
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($desc) > 300) $desc = mb_substr($desc, 0, 300).'…';
            } else {
                if (strlen($desc) > 300) $desc = substr($desc, 0, 300).'…';
            }

            echo '<tr>';
            echo '<td>'.intval($i).'</td>';
            echo '<td>'.kse_html($title).'</td>';
            echo '<td>'.kse_html($start).'</td>';
            echo '<td>'.kse_html($loc).'</td>';
            echo '<td>'.($img ? '<img class="kse-img" src="'.esc_url($img).'" alt="" />' : '&nbsp;').'</td>';
            echo '<td>'.($src ? '<a href="'.esc_url($src).'" target="_blank" rel="noopener">öffnen</a>' : '&nbsp;').'</td>';
            echo '<td class="kse-desc">'.kse_html($desc).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        if ($do_crawl) {
            echo '<p>Keine Events gefunden.</p>';
            echo '<p><small>Tipp: „Debug-Scan“ zeigt HTTP-Status der Programmseite und erkannte Detail-Links.</small></p>';
        } else {
            echo '<p>Klicke auf „Liste aktualisieren“, um die Vorschau zu laden.</p>';
        }
    }

    echo '</div>';
}}