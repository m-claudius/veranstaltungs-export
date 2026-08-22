<?php
/**
 * Musik in alten Heidekirchen – Admin-Vorschauseite
 *
 * INSTALLATION:
 *   Datei als  includes/miah-admin.php  speichern
 *   und in veranstaltungs-export.php einbinden (VOR menu.php):
 *     require_once KSE_PLUGIN_DIR . 'includes/miah-admin.php';
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('kse_render_miah')) {
function kse_render_miah() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

    $list_url  = defined('MusikInAltenHeidekirchenParser::LIST_URL')
        ? MusikInAltenHeidekirchenParser::LIST_URL
        : 'https://musik-in-alten-heidekirchen.wir-e.de/termine';
    $do_crawl  = isset($_GET['crawl']);
    $debug     = isset($_GET['debug']);
    $events    = [];
    $notes     = [];

    echo '<div class="wrap"><h1>Musik in alten Heidekirchen</h1>';

    // ──── Info-Box ────
    echo '<div class="notice notice-info"><p>';
    echo '<strong>Quelle:</strong> <a href="' . esc_url($list_url) . '" target="_blank">' . esc_html($list_url) . '</a>';
    echo '</p></div>';

    // ──── Buttons ────
    $refresh_url = esc_url(add_query_arg(['crawl' => 1]));
    $debug_url   = esc_url(add_query_arg(['crawl' => 1, 'debug' => 1]));
    echo '<p>';
    echo '<a class="button button-primary" href="' . $refresh_url . '">Liste aktualisieren</a> ';
    if (function_exists('kse_render_run_now_link')) {
        kse_render_run_now_link('miah');
    }
    echo ' <a class="button" href="' . $debug_url . '">Debug-Scan</a>';
    echo '</p>';

    // ──── Run-Now Erfolg ────
    if (isset($_GET['kse-run-done']) && $_GET['kse-run-done'] === 'miah') {
        echo '<div class="notice notice-success is-dismissible"><p><strong>MIAH-Import wurde erfolgreich ausgeführt!</strong></p></div>';
    }

    // ──── Crawl ────
    if ($do_crawl) {
        if (!class_exists('MusikInAltenHeidekirchenParser')) {
            echo '<div class="notice notice-error"><p>Parser-Klasse <code>MusikInAltenHeidekirchenParser</code> nicht gefunden.</p></div>';
        } else {
            $parser = new MusikInAltenHeidekirchenParser();

            // Debug: HTTP-Check der Hauptseite
            if ($debug) {
                $test_resp = wp_remote_get($list_url, ['timeout' => 20, 'headers' => ['User-Agent' => 'KSE-Debug/1.0']]);
                if (is_wp_error($test_resp)) {
                    $notes[] = 'HTTP-Check Terminseite: <span style="color:red">' . esc_html($test_resp->get_error_message()) . '</span>';
                } else {
                    $code = wp_remote_retrieve_response_code($test_resp);
                    $len  = strlen(wp_remote_retrieve_body($test_resp));
                    $notes[] = "HTTP-Check Terminseite: HTTP $code, $len Bytes";
                }
            }

            try {
                $events = (array) $parser->crawl($list_url, 0); // 0 = unlimitiert
                $notes[] = '<strong>' . count($events) . '</strong> Events gefunden und geparst';
            } catch (Throwable $e) {
                echo '<div class="notice notice-error"><p>Fehler: ' . esc_html($e->getMessage()) . '</p></div>';
            }

            // Debug: Detail-Link-Prüfung
            if ($debug && !empty($events)) {
                $first = $events[0];
                $has_title = !empty($first['title']);
                $has_date  = !empty($first['start']);
                $has_image = !empty($first['image']);
                $notes[] = 'Erstes Event: Titel=' . ($has_title ? '<span style="color:green">OK</span>' : '<span style="color:red">LEER</span>')
                    . ', Datum=' . ($has_date ? '<span style="color:green">OK</span>' : '<span style="color:red">LEER</span>')
                    . ', Bild=' . ($has_image ? '<span style="color:green">OK</span>' : '<span style="color:orange">LEER</span>');
            }
        }
    }

    // ──── Hinweise ────
    if (!empty($notes)) {
        echo '<div class="notice notice-info"><p>' . implode('<br/>', $notes) . '</p></div>';
    }

    // ──── Zähler ────
    $count = count($events);
    echo '<p><em>Gefunden: ' . intval($count) . '</em></p>';

    // ──── Vorschau-Tabelle ────
    if ($count > 0) {
        if (function_exists('kse_table_styles_inline')) {
            kse_table_styles_inline();
        } else {
            echo '<style>
                .kse-table{width:100%;border-collapse:collapse;margin-top:12px}
                .kse-table th,.kse-table td{border:1px solid #ddd;padding:8px;vertical-align:top}
                .kse-table th{background:#f6f7f7;text-align:left}
                .kse-img{max-width:160px;height:auto;display:block}
                .kse-desc{max-width:700px;word-wrap:break-word;white-space:normal}
            </style>';
        }

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

        $i = 0;
        foreach ($events as $ev) {
            $i++;
            $title = wp_strip_all_tags($ev['title'] ?? '');
            $start = $ev['start'] ?? ($ev['datetime'] ?? '');
            $end   = $ev['end'] ?? '';
            $loc   = $ev['location'] ?? '';
            $img   = $ev['image'] ?? '';
            $src   = $ev['source_url'] ?? '';
            $desc  = wp_strip_all_tags($ev['description'] ?? '');

            if (function_exists('mb_strlen') && mb_strlen($desc) > 300) {
                $desc = mb_substr($desc, 0, 300) . '…';
            } elseif (strlen($desc) > 300) {
                $desc = substr($desc, 0, 300) . '…';
            }

            $date_display = esc_html($start);
            if ($end && $end !== $start) {
                $date_display .= '<br/><small>bis ' . esc_html($end) . '</small>';
            }

            echo '<tr>';
            echo '<td>' . intval($i) . '</td>';
            echo '<td><strong>' . esc_html($title) . '</strong></td>';
            echo '<td>' . $date_display . '</td>';
            echo '<td>' . esc_html($loc) . '</td>';
            echo '<td>' . ($img ? '<img class="kse-img" src="' . esc_url($img) . '" alt="" />' : '&nbsp;') . '</td>';
            echo '<td>' . ($src ? '<a href="' . esc_url($src) . '" target="_blank" rel="noopener">öffnen</a>' : '&nbsp;') . '</td>';
            echo '<td class="kse-desc">' . esc_html($desc) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        if ($do_crawl) {
            echo '<p>Keine Events gefunden. Nutze „Debug-Scan" für Details.</p>';
        } else {
            echo '<p>Klicke auf „Liste aktualisieren", um die Vorschau zu laden.</p>';
        }
    }

    echo '</div>';
}
}
