<?php
if (!defined('ABSPATH')) exit;

/**
 * Einstellungen: Whitelist/Blacklist (Seevetal) + Cron-Intervalle je Quelle
 * Option: kse_options
 */

add_action('admin_init', 'kse_register_settings');
add_filter('cron_schedules', 'kse_add_custom_schedules');

function kse_add_custom_schedules($s) {
    // Wöchentlicher Intervall (generisch)
    $s['weekly'] = ['interval' => 7 * DAY_IN_SECONDS, 'display' => __('Einmal pro Woche')];
    // Wöchentlich (Samstag) – Intervall identisch, Startzeit planen wir gezielt
    $s['weekly_sat'] = ['interval' => 7 * DAY_IN_SECONDS, 'display' => __('Wöchentlich (Samstag)')];
    return $s;
}

function kse_register_settings() {
    register_setting('kse_settings', 'kse_options', [
        'type'              => 'array',
        'sanitize_callback' => 'kse_sanitize_options',
        'default'           => [],
    ]);

    add_settings_section('kse_sec_seevetal', 'Seevetal – Filter', '__return_false', 'kse_settings');

    add_settings_field('seevetal_whitelist', 'Whitelist (Komma-getrennt)', function(){
        $o = get_option('kse_options', []);
        echo '<input type="text" class="regular-text" name="kse_options[seevetal_whitelist]" value="'.esc_attr($o['seevetal_whitelist'] ?? 'Musik,Kultur').'" />';
        echo '<p class="description">Beispiele: <code>Musik,Kultur,Theater</code></p>';
    }, 'kse_settings', 'kse_sec_seevetal');

    add_settings_field('seevetal_blacklist', 'Blacklist (Komma-getrennt)', function(){
        $o = get_option('kse_options', []);
        echo '<input type="text" class="regular-text" name="kse_options[seevetal_blacklist]" value="'.esc_attr($o['seevetal_blacklist'] ?? '').'" />';
        echo '<p class="description">URLs, die diese Wörter enthalten, werden ausgeschlossen.</p>';
    }, 'kse_settings', 'kse_sec_seevetal');

    add_settings_section('kse_sec_cron', 'Automatischer Import (Cron)', '__return_false', 'kse_settings');

    add_settings_field('cron_miah', 'Musik in alten Heidekirchen', function(){
        $o = get_option('kse_options', []);
        $val = $o['cron_miah'] ?? 'disabled';
        kse_render_cron_select('kse_options[cron_miah]', $val);
        kse_render_cron_next('kse_cron_miah');
    }, 'kse_settings', 'kse_sec_cron');

    add_settings_field('cron_seevetal', 'Gemeinde Seevetal', function(){
        $o = get_option('kse_options', []);
        $val = $o['cron_seevetal'] ?? 'disabled';
        kse_render_cron_select('kse_options[cron_seevetal]', $val);
        kse_render_cron_next('kse_cron_seevetal');
    }, 'kse_settings', 'kse_sec_cron');
}

function kse_render_cron_select($name, $current) {
    $opts = [
        'disabled'    => 'Aus',
        'hourly'      => 'Stündlich',
        'twicedaily'  => '2× täglich',
        'daily'       => 'Täglich',
        'weekly_sat'  => 'Wöchentlich (Samstag)',
    ];
    echo '<select name="'.esc_attr($name).'">';
    foreach ($opts as $k => $label) {
        echo '<option value="'.esc_attr($k).'"'.selected($current,$k,false).'>'.esc_html($label).'</option>';
    }
    echo '</select> ';
    echo '<span class="description">Speichern, um Zeitplan zu aktualisieren.</span>';
}

function kse_render_cron_next($hook) {
    $ts = wp_next_scheduled($hook);
    if ($ts) {
        echo '<p class="description">Nächster Lauf: '.esc_html(date_i18n('d.m.Y H:i', $ts)).'</p>';
    } else {
        echo '<p class="description">Kein Zeitplan aktiv.</p>';
    }
}

function kse_sanitize_options($in) {
    $out = [];
    $out['seevetal_whitelist'] = sanitize_text_field($in['seevetal_whitelist'] ?? '');
    $out['seevetal_blacklist'] = sanitize_text_field($in['seevetal_blacklist'] ?? '');
    $allow = ['disabled','hourly','twicedaily','daily','weekly_sat'];
    $out['cron_miah']     = in_array(($in['cron_miah'] ?? 'disabled'), $allow, true) ? $in['cron_miah'] : 'disabled';
    $out['cron_seevetal'] = in_array(($in['cron_seevetal'] ?? 'disabled'), $allow, true) ? $in['cron_seevetal'] : 'disabled';

    // Zeitpläne anwenden
    kse_reschedule_cron('kse_cron_miah',     $out['cron_miah']);
    kse_reschedule_cron('kse_cron_seevetal', $out['cron_seevetal']);

    return $out;
}

function kse_reschedule_cron($hook, $freq) {
    // Vorhandene Jobs leeren
    while ($ts = wp_next_scheduled($hook)) {
        wp_unschedule_event($ts, $hook);
    }
    if (!$freq || $freq === 'disabled') return;

    // Startzeit setzen
    if ($freq === 'weekly_sat') {
        // Nächsten Samstag 04:00 (Serverzeit / WP-Zeit)
        $tz = wp_timezone();
        $now = new DateTime('now', $tz);
        $start = clone $now;
        // auf nächsten Samstag springen
        // (Samstag = 6, PHP: 0=So ... 6=Sa)
        $dow = (int)$now->format('w');
        $addDays = ($dow <= 6) ? (6 - $dow) : 0;
        if ($addDays === 0 && (int)$now->format('H') >= 4) $addDays = 7; // heute schon nach 04:00 -> nächste Woche
        $start->modify("+{$addDays} days")->setTime(4,0,0);
        wp_schedule_event($start->getTimestamp(), 'weekly_sat', $hook);
    } else {
        // sofort/kurzfristig starten
        wp_schedule_event(time() + 60, $freq, $hook);
    }
}

/* -------- Settings-Seite rendern -------- */

function kse_render_settings_admin() {
    echo '<div class="wrap"><h1>Einstellungen</h1>
    <form method="post" action="options.php">';
    settings_fields('kse_settings');
    do_settings_sections('kse_settings');
    submit_button();
    echo '</form></div>';
}

/* -------- Cron-Runner (Live-Import) -------- */

add_action('kse_cron_miah', 'kse_cron_run_miah');
function kse_cron_run_miah() {
    if (!class_exists('MusikInAltenHeidekirchenParser') || !function_exists('kse_tec_upsert_event')) return;
    $parser  = new MusikInAltenHeidekirchenParser();
    $listUrl = defined('MusikInAltenHeidekirchenParser::LIST_URL')
        ? MusikInAltenHeidekirchenParser::LIST_URL
        : 'https://musik-in-alten-heidekirchen.wir-e.de/termine';
    $events  = $parser->crawl($listUrl, 100);

    $scanned = count($events); $created=$updated=$skipped=0;

    foreach ($events as $ev) {
        $src = $ev['source_url'] ?? ($ev['source'] ?? '');
        if (!$src) { $skipped++; continue; }
        $res = kse_tec_upsert_event([
            'title'       => $ev['title'] ?? '',
            'start'       => $ev['start'] ?? ($ev['datetime'] ?? ''),
            'description' => $ev['description'] ?? ($ev['description_html'] ?? ($ev['desc'] ?? '')),
            'location'    => $ev['location'] ?? '',
            'image'       => $ev['image'] ?? '',
            'source_url'  => $src,
        ], [
            'default_duration_minutes' => 120,
            'category'                 => 'Musik in alten Heidekirchen',
        ]);
        if (($res['action'] ?? '') === 'updated') $updated++;
        elseif (($res['action'] ?? '') === 'created') $created++;
        else $skipped++;
    }

    if (function_exists('kse_stats_log')) {
        kse_stats_log('MIAH', compact('scanned','created','updated','skipped') + ['blacklisted'=>0]);
    }
}

add_action('kse_cron_seevetal', 'kse_cron_run_seevetal');
function kse_cron_run_seevetal() {
    if (!class_exists('SeevetalParser') || !function_exists('kse_tec_upsert_event')) return;
    $opts = get_option('kse_options', []);
    $wl = array_values(array_filter(array_map('trim', explode(',', $opts['seevetal_whitelist'] ?? 'Musik,Kultur'))));
    $bl = array_values(array_filter(array_map('trim', explode(',', $opts['seevetal_blacklist'] ?? ''))));

    $parser = new SeevetalParser();
    $links = [];
    foreach ($wl as $term) {
        $links = array_merge($links, $parser->collect_detail_links($term));
    }
    $links = array_values(array_unique($links));

    $blacklisted = 0;
    if ($bl) {
        $links = array_values(array_filter($links, function ($u) use ($bl, &$blacklisted) {
            foreach ($bl as $b) {
                if ($b !== '' && stripos($u, $b) !== false) { $blacklisted++; return false; }
            }
            return true;
        }));
    }

    $scanned = count($links); $created=$updated=$skipped=0;

    foreach ($links as $u) {
        $ev = $parser->get_cached_detail($u);
        if (!$ev) { $skipped++; continue; }
        $res = kse_tec_upsert_event([
            'title'       => $ev['title'] ?? '',
            'start'       => $ev['datetime'] ?? '',
            'description' => $ev['desc'] ?? ($ev['description'] ?? ''),
            'location'    => $ev['location'] ?? '',
            'image'       => $ev['image'] ?? '',
            'source_url'  => $u,
        ], [
            'default_duration_minutes' => 120,
            'category'                 => 'Gemeinde Seevetal',
        ]);
        if (($res['action'] ?? '') === 'updated') $updated++;
        elseif (($res['action'] ?? '') === 'created') $created++;
        else $skipped++;
    }

    if (function_exists('kse_stats_log')) {
        kse_stats_log('SEEVETAL', compact('scanned','created','updated','skipped') + ['blacklisted'=>$blacklisted]);
    }
}
