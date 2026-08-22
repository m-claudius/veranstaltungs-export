<?php
/**
 * Burg Seevetal – Cron-Runner & Einstellungen-Integration
 *
 * INSTALLATION:
 *   Diese Datei als  includes/burg-seevetal-cron.php  speichern
 *   und in veranstaltungs-export.php einbinden:
 *     require_once KSE_PLUGIN_DIR . 'includes/burg-seevetal-cron.php';
 *
 *   Außerdem in admin/settings.php die Felder für Cron und "Jetzt starten" ergänzen
 *   (siehe INSTALL.md für die genauen Code-Snippets).
 */
if (!defined('ABSPATH')) exit;

/* =========================================================
 * CRON-RUNNER: Burg Seevetal → The Events Calendar
 * =======================================================*/
add_action('kse_cron_burg', 'kse_cron_run_burg');

function kse_cron_run_burg() {
    if (!class_exists('BurgSeevetalParser') || !function_exists('kse_tec_upsert_event')) {
        if (function_exists('kse_stats_log')) {
            kse_stats_log('BURG', [
                'scanned' => 0, 'created' => 0, 'updated' => 0,
                'skipped' => 0, 'blacklisted' => 0, 'duplicates' => 0,
                'note' => 'Parser/Import-Helfer fehlen',
            ]);
        }
        return;
    }

    $parser = new BurgSeevetalParser();
    $links  = $parser->collect_all_detail_links(10);

    $scanned = count($links);
    $created = $updated = $skipped = $duplicates = 0;

    foreach ($links as $detail_url) {
        $ev = $parser->get_cached_detail($detail_url);
        if (empty($ev['title'])) {
            $skipped++;
            continue;
        }

        // ──── Duplikat-Check: Existiert dieses Event bereits (z.B. von Gemeinde Seevetal)? ────
        // Prüft per Titel + Startdatum, ob ein identisches Event aus einer ANDEREN Quelle existiert
        if (function_exists('kse_find_event_by_title_and_start')) {
            $existing_id = kse_find_event_by_title_and_start($ev['title'], $ev['datetime'] ?? '');
            if ($existing_id) {
                // Prüfen ob es von einer anderen Quelle stammt
                $existing_slug = get_post_meta($existing_id, '_kse_source_slug', true);
                if ($existing_slug && $existing_slug !== 'burg') {
                    // Event existiert bereits von anderer Quelle → überspringen
                    $duplicates++;
                    error_log('[KSE-Burg] Duplikat übersprungen: "' . $ev['title'] . '" (existiert als ' . $existing_slug . ')');
                    continue;
                }
            }
        }

        // ──── Event in TEC importieren ────
        $desc = $ev['desc'] ?? '';

        // Ticket-Info an Beschreibung anhängen (wenn vorhanden)
        $ticket_url = $ev['ticket_url'] ?? '';
        $costs      = $ev['costs'] ?? '';
        if ($ticket_url || $costs) {
            $desc .= "\n\n";
            if ($costs) $desc .= '<p><strong>Eintritt:</strong> ' . esc_html($costs) . '</p>';
            if ($ticket_url) $desc .= '<p><strong>Tickets:</strong> <a href="' . esc_url($ticket_url) . '" target="_blank">Online buchen</a></p>';
        }

        $res = kse_tec_upsert_event([
            'title'       => $ev['title'],
            'start'       => $ev['datetime'] ?? '',
            'end'         => $ev['end'] ?? '',
            'description' => $desc,
            'location'    => $ev['location'] ?? 'Burg Seevetal, Am Göhlenbach 11, 21218 Seevetal',
            'image'       => $ev['image'] ?? '',
            'source_url'  => $detail_url,
        ], [
            'default_duration_minutes' => 120,
            'category'                 => 'Burg Seevetal',
            'source_slug'              => 'burg',
        ]);

        // Kategorie erzwingen (wie bei Empore/Winsen)
        $post_id = (int) ($res['post_id'] ?? 0);
        if ($post_id > 0 && function_exists('kse_force_event_category')) {
            kse_force_event_category($post_id, 'Burg Seevetal', ['Gemeinde Seevetal']);
        }

        $act = $res['action'] ?? '';
        if ($act === 'created')     { $created++; }
        elseif ($act === 'updated') { $updated++; }
        else                        { $skipped++; }
    }

    if (function_exists('kse_stats_log')) {
        kse_stats_log('BURG', compact('scanned', 'created', 'updated', 'skipped', 'duplicates'));
    }

    error_log("[KSE-Burg] Cron fertig: scanned=$scanned created=$created updated=$updated skipped=$skipped duplicates=$duplicates");
}

/* =========================================================
 * "Jetzt starten" Handler: Burg-Quellcode in die Map einhängen
 * =======================================================*/
// Der bestehende kse_admin_run_source() in settings.php hat eine $map.
// Wir erweitern sie hier per Filter/Hook-Ansatz:
// Falls 'burg' noch nicht drin ist, fügen wir den Action-Hook hinzu.
// (Alternativ: einfach die $map in settings.php manuell erweitern – siehe INSTALL.md)
add_action('admin_init', function () {
    // Sicherstellen, dass kse_cron_burg als Action registriert ist
    if (!has_action('kse_cron_burg', 'kse_cron_run_burg')) {
        add_action('kse_cron_burg', 'kse_cron_run_burg');
    }
});
