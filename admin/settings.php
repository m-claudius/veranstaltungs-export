<?php
if (!defined('ABSPATH')) exit;

/** Optionen registrieren */
add_action('admin_init', function () {
    register_setting('kse_options_group', 'kse_options');
});

function kse_render_settings_admin() {
    $o = get_option('kse_options', []);

    // Seevetal Suchworte
    $wl = $o['seevetal_whitelist'] ?? 'Musik,Kultur';
    $bl = $o['seevetal_blacklist'] ?? '';

    // Cron MIAH
    $miah_on   = !empty($o['cron_miah_on']);
    $miah_rec  = $o['cron_miah_rec'] ?? 'daily';       // hourly | twicedaily | daily
    $miah_time = $o['cron_miah_time'] ?? '04:30';

    // Cron Seevetal
    $sev_on    = !empty($o['cron_sev_on']);
    $sev_rec   = $o['cron_sev_rec'] ?? 'daily';
    $sev_time  = $o['cron_sev_time'] ?? '05:00';

    // DRY RUN
    $dry = !empty($o['dry_run_default']);

    echo '<div class="wrap"><h1>Einstellungen</h1>';
    echo '<form method="post" action="options.php">';
    settings_fields('kse_options_group');

    echo '<h2>Gemeinde Seevetal – Suchworte</h2>';
    echo '<table class="form-table">
            <tr><th>Whitelist (kommasepariert)</th><td>
                <input name="kse_options[seevetal_whitelist]" type="text" class="regular-text" value="'.esc_attr($wl).'" />
                <p class="description">Beispiele: Musik,Kultur</p></td></tr>
            <tr><th>Blacklist (kommasepariert)</th><td>
                <input name="kse_options[seevetal_blacklist]" type="text" class="regular-text" value="'.esc_attr($bl).'" />
                <p class="description">Begriffe, die ausgeschlossen werden sollen.</p></td></tr>
          </table>';

    echo '<h2>Cron – Zeitplan</h2>';
    echo '<table class="form-table">
            <tr><th>Musik in alten Heidekirchen</th><td>
                <label><input type="checkbox" name="kse_options[cron_miah_on]" value="1" '.checked($miah_on,true,false).'> aktiv</label><br>
                Intervall:
                <select name="kse_options[cron_miah_rec]">
                    <option value="hourly" '.selected($miah_rec,'hourly',false).'>stündlich</option>
                    <option value="twicedaily" '.selected($miah_rec,'twicedaily',false).'>2× täglich</option>
                    <option value="daily" '.selected($miah_rec,'daily',false).'>täglich</option>
                </select>
                &nbsp;Uhrzeit (für daily/2×daily): <input name="kse_options[cron_miah_time]" type="time" value="'.esc_attr($miah_time).'" />
            </td></tr>
            <tr><th>Gemeinde Seevetal</th><td>
                <label><input type="checkbox" name="kse_options[cron_sev_on]" value="1" '.checked($sev_on,true,false).'> aktiv</label><br>
                Intervall:
                <select name="kse_options[cron_sev_rec]">
                    <option value="hourly" '.selected($sev_rec,'hourly',false).'>stündlich</option>
                    <option value="twicedaily" '.selected($sev_rec,'twicedaily',false).'>2× täglich</option>
                    <option value="daily" '.selected($sev_rec,'daily',false).'>täglich</option>
                </select>
                &nbsp;Uhrzeit (für daily/2×daily): <input name="kse_options[cron_sev_time]" type="time" value="'.esc_attr($sev_time).'" />
            </td></tr>
            <tr><th>Import-Modus</th><td>
                <label><input type="checkbox" name="kse_options[dry_run_default]" value="1" '.checked($dry,true,false).'> Standard: DRY RUN</label>
                <p class="description">Wenn aktiv, laufen automatische Cron-Läufe nur als Probelauf (ohne TEC-Import).</p>
            </td></tr>
          </table>';

    submit_button('Speichern');
    echo '</form></div>';
}

/** Nach dem Speichern Cron (neu) planen */
add_action('update_option_kse_options', function ($old, $new) {
    // MIAH
    wp_clear_scheduled_hook('kse_cron_run_miah');
    if (!empty($new['cron_miah_on'])) {
        $rec  = in_array(($new['cron_miah_rec'] ?? 'daily'), ['hourly','twicedaily','daily'], true) ? $new['cron_miah_rec'] : 'daily';
        $time = $new['cron_miah_time'] ?? '04:30';
        $ts   = kse_next_timestamp($time);
        wp_schedule_event($ts, $rec, 'kse_cron_run_miah');
    }

    // Seevetal
    wp_clear_scheduled_hook('kse_cron_run_sev');
    if (!empty($new['cron_sev_on'])) {
        $rec  = in_array(($new['cron_sev_rec'] ?? 'daily'), ['hourly','twicedaily','daily'], true) ? $new['cron_sev_rec'] : 'daily';
        $time = $new['cron_sev_time'] ?? '05:00';
        $ts   = kse_next_timestamp($time);
        wp_schedule_event($ts, $rec, 'kse_cron_run_sev');
    }
}, 10, 2);

/** Helfer: nächste Uhrzeit (heute oder morgen) */
function kse_next_timestamp(string $hhmm): int {
    if (!preg_match('/^(\d{2}):(\d{2})$/', $hhmm, $m)) return time() + HOUR_IN_SECONDS;
    $now = current_time('timestamp');
    $today = strtotime(date('Y-m-d', $now).' '.$m[1].':'.$m[2].':00');
    return ($today > $now) ? $today : strtotime('+1 day', $today);
}

/** Cron-Handler – standardmäßig nur Logging (DRY_RUN).
 *  Wenn du sofort echte Importe willst: kse_import_event_to_tec($row) hier aufrufen.
 */
add_action('kse_cron_run_miah', function () {
    $opts = get_option('kse_options', []);
    $dry  = !empty($opts['dry_run_default']);

    if (!class_exists('MusikInAltenHeidekirchenParser')) return;
    $parser = new MusikInAltenHeidekirchenParser();
    $events = $parser->crawl(MusikInAltenHeidekirchenParser::LIST_URL, 30);

    $n = 0;
    foreach ($events as $ev) {
        // hier ggf. mappen + importieren
        $n++;
    }
    error_log(sprintf('[KSE] Cron MIAH: %d Events (%s)', $n, $dry ? 'DRY_RUN' : 'LIVE'));
});

add_action('kse_cron_run_sev', function () {
    $opts = get_option('kse_options', []);
    $dry  = !empty($opts['dry_run_default']);

    if (!class_exists('SeevetalParser')) return;
    $wl = array_filter(array_map('trim', explode(',', $opts['seevetal_whitelist'] ?? 'Musik,Kultur')));
    $bl = array_filter(array_map('trim', explode(',', $opts['seevetal_blacklist'] ?? '')));

    $parser = new SeevetalParser();
    $links  = [];
    foreach ($wl as $term) {
        $links = array_merge($links, $parser->collect_detail_links($term));
    }
    $links = array_values(array_unique($links));
    if ($bl) {
        $links = array_values(array_filter($links, function ($u) use ($bl) {
            foreach ($bl as $b) { if ($b!=='' && stripos($u,$b)!==false) return false; }
            return true;
        }));
    }

    $n = 0;
    foreach (array_slice($links, 0, 50) as $u) {
        $ev = $parser->get_cached_detail($u);
        // hier ggf. mappen + importieren
        $n++;
    }
    error_log(sprintf('[KSE] Cron Seevetal: %d Links (%s) – WL=%s BL=%s',
        $n, $dry ? 'DRY_RUN' : 'LIVE', implode(',', $wl), implode(',', $bl)));
});
