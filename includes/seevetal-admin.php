<?php
/**
 * Gemeinde Seevetal – Admin-Vorschauseite mit Debug-Scan, Vorschau & Run-Now
 *
 * INSTALLATION:
 *   Den bestehenden Block „kse_render_seevetal" in menu.php ersetzen
 *   (alles zwischen den Kommentaren "Gemeinde Seevetal" und dem nächsten Abschnitt).
 *
 *   Alternativ: Diese Datei als  includes/seevetal-admin.php  speichern
 *   und in veranstaltungs-export.php einbinden:
 *     require_once KSE_PLUGIN_DIR . 'includes/seevetal-admin.php';
 *   BEVOR menu.php geladen wird (damit function_exists-Guard greift).
 */
if (!defined('ABSPATH')) exit;

/* =========================================================
 * GEMEINDE SEEVETAL – Vorschau + Debug-Scan + Run-Now
 * =======================================================*/
if (!function_exists('kse_render_seevetal')) {
function kse_render_seevetal() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

    $opts      = get_option('kse_options', []);
    $whitelist = $opts['seevetal_whitelist'] ?? 'Musik,Kultur';
    $blacklist = $opts['seevetal_blacklist'] ?? '';
    $do_crawl  = isset($_GET['crawl']);
    $debug     = isset($_GET['debug']);
    $events    = [];
    $all_links = [];
    $notes     = [];

    echo '<div class="wrap"><h1>Gemeinde Seevetal</h1>';

    // ──── Info-Box: Aktuelle Einstellungen ────
    echo '<div class="notice notice-info"><p>';
    echo '<strong>Whitelist:</strong> ' . esc_html($whitelist) . ' &nbsp;|&nbsp; ';
    echo '<strong>Blacklist:</strong> ' . esc_html($blacklist ?: '(leer)');
    echo ' &nbsp;|&nbsp; <a href="' . esc_url(admin_url('admin.php?page=kse-settings')) . '">Einstellungen ändern</a>';
    echo '</p></div>';

    // ──── Buttons ────
    $refresh_url = esc_url(add_query_arg(['crawl' => 1]));
    $debug_url   = esc_url(add_query_arg(['crawl' => 1, 'debug' => 1]));
    echo '<p>';
    echo '<a class="button button-primary" href="' . $refresh_url . '">Liste aktualisieren</a> ';
    if (function_exists('kse_render_run_now_link')) {
        kse_render_run_now_link('seevetal');
    }
    echo ' <a class="button" href="' . $debug_url . '">Debug-Scan</a>';
    echo '</p>';

    // ──── Run-Now Erfolg anzeigen ────
    if (isset($_GET['kse-run-done']) && $_GET['kse-run-done'] === 'seevetal') {
        echo '<div class="notice notice-success is-dismissible"><p><strong>Seevetal-Import wurde erfolgreich ausgeführt!</strong> Prüfe die Statistik-Seite für Details.</p></div>';
    }

    // ──── Crawler ausführen ────
    if ($do_crawl) {
        if (!class_exists('SeevetalParser')) {
            echo '<div class="notice notice-error"><p>Parser-Klasse <code>SeevetalParser</code> wurde nicht gefunden. Bitte sicherstellen, dass <code>crawler/SeevetalParser.php</code> geladen wird.</p></div>';
        } else {
            $parser = new SeevetalParser();
            $wl = array_values(array_filter(array_map('trim', explode(',', $whitelist))));
            $bl = array_values(array_filter(array_map('trim', explode(',', $blacklist))));

            // Links pro Suchbegriff sammeln
            foreach ($wl as $term) {
                $term_links = $parser->collect_detail_links($term);
                if ($debug) {
                    $notes[] = "Suchbegriff <strong>\"" . esc_html($term) . "\"</strong>: " . count($term_links) . " Detail-Links gefunden";
                }
                $all_links = array_merge($all_links, $term_links);
            }
            // Genauso entdoppeln wie der Import: Nolis liefert dieselbe
            // Veranstaltung zusätzlich unter /buchen/ aus.
            $all_links = function_exists('kse_unique_links_by_identity')
                ? kse_unique_links_by_identity($all_links, 'seevetal')
                : array_values(array_unique($all_links));

            // Blacklist anwenden
            $blacklisted = 0;
            if ($bl) {
                $all_links = array_values(array_filter($all_links, function ($u) use ($bl, &$blacklisted) {
                    foreach ($bl as $b) {
                        if ($b !== '' && stripos($u, $b) !== false) {
                            $blacklisted++;
                            return false;
                        }
                    }
                    return true;
                }));
            }
            if ($debug && $blacklisted > 0) {
                $notes[] = "$blacklisted Links durch Blacklist gefiltert";
            }

            $notes[] = "Gesamt: <strong>" . count($all_links) . "</strong> Detail-Links nach Deduplizierung" . ($blacklisted ? " ($blacklisted blacklisted)" : "");

            // Detailseiten parsen
            foreach ($all_links as $detail_url) {
                try {
                    $ev = $parser->get_cached_detail($detail_url);
                    if (!empty($ev['title'])) {
                        $ev['source_url'] = $detail_url;
                        $events[] = $ev;
                    } else {
                        if ($debug) {
                            $notes[] = "Leerer Titel für: " . esc_html($detail_url);
                        }
                    }
                } catch (Throwable $e) {
                    if ($debug) {
                        $notes[] = "Fehler bei " . esc_html($detail_url) . ": " . esc_html($e->getMessage());
                    }
                }
            }
        }
    }

    // ──── Debug-Scan: zusätzliche HTTP-Prüfungen ────
    if ($do_crawl && $debug && class_exists('SeevetalParser')) {
        // Erste 2 Detailseiten per HTTP prüfen
        $test_links = array_slice($all_links, 0, 2);
        foreach ($test_links as $tl) {
            $test_resp = wp_remote_get($tl, ['timeout' => 15, 'headers' => ['User-Agent' => 'KSE-Debug/1.0']]);
            if (is_wp_error($test_resp)) {
                $notes[] = 'Detail-HTTP-Check: ' . esc_html($tl) . ' → <span style="color:red">FEHLER: ' . esc_html($test_resp->get_error_message()) . '</span>';
            } else {
                $code = wp_remote_retrieve_response_code($test_resp);
                $len  = strlen(wp_remote_retrieve_body($test_resp));
                $notes[] = 'Detail-HTTP-Check: ' . esc_html($tl) . " → HTTP $code, $len Bytes";
            }
        }

        // JSON-LD Check
        if (!empty($events[0])) {
            $first = $events[0];
            $has_jsonld = !empty($first['datetime']) && $first['datetime'] !== '0000-00-00 00:00:00';
            $notes[] = 'JSON-LD Parsing: ' . ($has_jsonld ? '<span style="color:green">OK</span>' : '<span style="color:orange">Kein Datum aus JSON-LD</span>');
        }
    }

    // ──── Hinweise ausgeben ────
    if (!empty($notes)) {
        echo '<div class="notice notice-info"><p>' . implode('<br/>', $notes) . '</p></div>';
    }

    // ──── Detail-Links im Debug-Mode ────
    if ($do_crawl && $debug && !empty($all_links)) {
        echo '<h2>Gefundene Detail-Links (Top 30)</h2><ol style="font-size:12px;">';
        $i = 0;
        foreach ($all_links as $u) {
            echo '<li><a href="' . esc_url($u) . '" target="_blank" rel="noopener">' . esc_html($u) . '</a></li>';
            if (++$i >= 30) break;
        }
        echo '</ol>';
    }

    // ──── Ergebnis-Zähler ────
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
            $start = $ev['datetime'] ?? '';
            $end   = $ev['end'] ?? '';
            $loc   = $ev['location'] ?? '';
            $img   = $ev['image'] ?? '';
            $src   = $ev['source_url'] ?? '';
            $desc  = wp_strip_all_tags($ev['desc'] ?? ($ev['description'] ?? ''));
            $rub   = $ev['rubrik'] ?? '';

            // Beschreibung kürzen
            if (function_exists('mb_strlen') && mb_strlen($desc) > 300) {
                $desc = mb_substr($desc, 0, 300) . '…';
            } elseif (strlen($desc) > 300) {
                $desc = substr($desc, 0, 300) . '…';
            }

            // Datum anzeigen
            $date_display = esc_html($start);
            if ($end && $end !== $start) {
                $date_display .= ' – ' . esc_html($end);
            }

            echo '<tr>';
            echo '<td>' . intval($i) . '</td>';
            echo '<td><strong>' . esc_html($title) . '</strong>';
            if ($rub) {
                echo '<br/><small style="color:#666">' . esc_html($rub) . '</small>';
            }
            echo '</td>';
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
            echo '<p>Keine Events gefunden.</p>';
            echo '<p><small>Tipp: „Debug-Scan" zeigt HTTP-Status und erkannte Detail-Links. Prüfe auch die Whitelist in den <a href="' . esc_url(admin_url('admin.php?page=kse-settings')) . '">Einstellungen</a>.</small></p>';
        } else {
            echo '<p>Klicke auf „Liste aktualisieren", um die Vorschau zu laden.</p>';
        }
    }

    echo '</div>'; // .wrap
}
} // end function_exists
