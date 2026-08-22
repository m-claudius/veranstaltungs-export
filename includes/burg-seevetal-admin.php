<?php
/**
 * Burg Seevetal – Admin-Vorschauseite mit Vorschau, Debug-Scan & Run-Now
 *
 * INSTALLATION:
 *   1. Datei als includes/burg-seevetal-admin.php speichern
 *   2. In veranstaltungs-export.php einbinden (VOR menu.php):
 *        require_once KSE_PLUGIN_DIR . 'includes/burg-seevetal-admin.php';
 *   3. In admin/menu.php eine Submenu-Zeile hinzufügen (siehe INSTALL.md)
 */
if (!defined('ABSPATH')) exit;

/* =========================================================
 * BURG SEEVETAL – Vorschau + Debug-Scan + Run-Now
 * =======================================================*/
if (!function_exists('kse_render_burg')) {
function kse_render_burg() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

    $do_crawl  = isset($_GET['crawl']);
    $debug     = isset($_GET['debug']);
    $events    = [];
    $all_links = [];
    $notes     = [];

    echo '<div class="wrap"><h1>Burg Seevetal</h1>';

    // ──── Info-Box ────
    echo '<div class="notice notice-info"><p>';
    echo '<strong>Quelle:</strong> <a href="https://www.burg-seevetal.de/portal/veranstaltungen/suche.html?titel=Veranstaltungen" target="_blank">burg-seevetal.de</a>';
    echo ' &nbsp;|&nbsp; Alle Veranstaltungen automatisch (keine Suchbegriff-Filter)';
    echo ' &nbsp;|&nbsp; Duplikate mit Gemeinde Seevetal werden beim Import erkannt';
    echo '</p></div>';

    // ──── Buttons ────
    $refresh_url = esc_url(add_query_arg(['crawl' => 1]));
    $debug_url   = esc_url(add_query_arg(['crawl' => 1, 'debug' => 1]));
    echo '<p>';
    echo '<a class="button button-primary" href="' . $refresh_url . '">Liste aktualisieren</a> ';
    if (function_exists('kse_render_run_now_link')) {
        kse_render_run_now_link('burg');
    }
    echo ' <a class="button" href="' . $debug_url . '">Debug-Scan</a>';
    echo '</p>';

    // ──── Run-Now Erfolg ────
    if (isset($_GET['kse-run-done']) && $_GET['kse-run-done'] === 'burg') {
        echo '<div class="notice notice-success is-dismissible"><p><strong>Burg-Seevetal-Import wurde erfolgreich ausgeführt!</strong></p></div>';
    }

    // ──── Crawl ausführen ────
    if ($do_crawl) {
        if (!class_exists('BurgSeevetalParser')) {
            echo '<div class="notice notice-error"><p>Parser-Klasse <code>BurgSeevetalParser</code> nicht gefunden. Bitte <code>crawler/BurgSeevetalParser.php</code> prüfen.</p></div>';
        } else {
            $parser = new BurgSeevetalParser();

            // Links sammeln (über alle Seiten)
            $all_links = $parser->collect_all_detail_links(10);
            $notes[] = '<strong>' . count($all_links) . '</strong> Detail-Links gesammelt (alle Seiten)';

            // Detailseiten parsen
            foreach ($all_links as $detail_url) {
                try {
                    $ev = $parser->get_cached_detail($detail_url);
                    if (!empty($ev['title'])) {
                        $ev['source_url'] = $detail_url;
                        $events[] = $ev;
                    } elseif ($debug) {
                        $notes[] = 'Leerer Titel: ' . esc_html($detail_url);
                    }
                } catch (Throwable $e) {
                    if ($debug) {
                        $notes[] = 'Fehler: ' . esc_html($detail_url) . ' → ' . esc_html($e->getMessage());
                    }
                }
            }

            $notes[] = '<strong>' . count($events) . '</strong> Events erfolgreich geparst';
        }
    }

    // ──── Debug: HTTP-Checks ────
    if ($do_crawl && $debug && !empty($all_links)) {
        $test = array_slice($all_links, 0, 2);
        foreach ($test as $tl) {
            $resp = wp_remote_get($tl, ['timeout' => 15, 'headers' => ['User-Agent' => 'KSE-Debug/1.0']]);
            if (is_wp_error($resp)) {
                $notes[] = 'HTTP-Check: ' . esc_html($tl) . ' → <span style="color:red">' . esc_html($resp->get_error_message()) . '</span>';
            } else {
                $code = wp_remote_retrieve_response_code($resp);
                $len  = strlen(wp_remote_retrieve_body($resp));
                $notes[] = 'HTTP-Check: ' . esc_html(basename($tl)) . " → HTTP $code, $len Bytes";
            }
        }

        // JSON-LD Check
        if (!empty($events[0]['datetime']) && $events[0]['datetime'] !== '') {
            $notes[] = 'JSON-LD: <span style="color:green">OK (Datum aus JSON-LD extrahiert)</span>';
        } else {
            $notes[] = 'JSON-LD: <span style="color:orange">Kein Datum aus JSON-LD – Fallback auf XPath</span>';
        }

        // Duplikat-Warnung
        $dup_count = 0;
        if (function_exists('kse_find_event_by_title_and_start')) {
            foreach (array_slice($events, 0, 5) as $ev) {
                $existing = kse_find_event_by_title_and_start($ev['title'] ?? '', $ev['datetime'] ?? '');
                if ($existing) $dup_count++;
            }
        }
        if ($dup_count > 0) {
            $notes[] = "Duplikat-Check (Stichprobe): <strong>$dup_count</strong> der ersten 5 Events existieren bereits in TEC";
        }
    }

    // ──── Hinweise ────
    if (!empty($notes)) {
        echo '<div class="notice notice-info"><p>' . implode('<br/>', $notes) . '</p></div>';
    }

    // ──── Links im Debug ────
    if ($do_crawl && $debug && !empty($all_links)) {
        echo '<h2>Detail-Links (alle)</h2><ol style="font-size:12px;">';
        foreach ($all_links as $u) {
            echo '<li><a href="' . esc_url($u) . '" target="_blank" rel="noopener">' . esc_html(basename($u)) . '</a></li>';
        }
        echo '</ol>';
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
                <th>Bild</th>
                <th>Tickets</th>
                <th>Quelle</th>
                <th>Beschreibung (Auszug)</th>
              </tr></thead><tbody>';

        $i = 0;
        foreach ($events as $ev) {
            $i++;
            $title = wp_strip_all_tags($ev['title'] ?? '');
            $start = $ev['datetime'] ?? '';
            $end   = $ev['end'] ?? '';
            $img   = $ev['image'] ?? '';
            $src   = $ev['source_url'] ?? '';
            $desc  = wp_strip_all_tags($ev['desc'] ?? '');
            $ticket = $ev['ticket_url'] ?? '';
            $costs  = $ev['costs'] ?? '';

            if (function_exists('mb_strlen') && mb_strlen($desc) > 250) {
                $desc = mb_substr($desc, 0, 250) . '…';
            } elseif (strlen($desc) > 250) {
                $desc = substr($desc, 0, 250) . '…';
            }

            $date_display = esc_html($start);
            if ($end && $end !== $start) {
                $date_display .= '<br/><small>bis ' . esc_html($end) . '</small>';
            }

            echo '<tr>';
            echo '<td>' . intval($i) . '</td>';
            echo '<td><strong>' . esc_html($title) . '</strong>';
            if ($costs) echo '<br/><small>' . esc_html($costs) . '</small>';
            echo '</td>';
            echo '<td>' . $date_display . '</td>';
            echo '<td>' . ($img ? '<img class="kse-img" src="' . esc_url($img) . '" alt="" />' : '&nbsp;') . '</td>';
            echo '<td>';
            if ($ticket) echo '<a href="' . esc_url($ticket) . '" target="_blank">Tickets</a>';
            echo '</td>';
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
} // end function_exists
