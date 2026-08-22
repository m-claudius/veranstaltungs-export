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

    add_settings_field('cron_empore', 'Empore Buchholz', function(){
        $o = get_option('kse_options', []);
        $val = $o['cron_empore'] ?? 'disabled';
        kse_render_cron_select('kse_options[cron_empore]', $val);
        kse_render_cron_next('kse_cron_empore');
        if (function_exists('kse_render_run_now_link')) kse_render_run_now_link('empore'); // <— NEU
    }, 'kse_settings', 'kse_sec_cron');

    add_settings_field('cron_winsen', 'Kulturverein Winsen', function(){
        $o = get_option('kse_options', []);
        $val = $o['cron_winsen'] ?? 'disabled';
        kse_render_cron_select('kse_options[cron_winsen]', $val);
        kse_render_cron_next('kse_cron_winsen');
        if (function_exists('kse_render_run_now_link')) kse_render_run_now_link('winsen'); // <— NEU
    }, 'kse_settings', 'kse_sec_cron');
/*
    $fix_url = wp_nonce_url(admin_url('admin-post.php?action=kse_fix_terms'), 'kse_fix_terms');
    echo '<h2>Tools</h2>';
    echo '<p><a href="'.esc_url($fix_url).'" class="button button-secondary">Kategorien reparieren (Winsen/Empore → korrekt zuordnen)</a></p>';
*/
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
    // $out['cron_empore']   = in_array(($in['cron_empore'] ?? 'disabled'), $allow, true) ? $in['cron_empore'] : 'disabled';
    // $out['cron_winsen']   = in_array(($in['cron_winsen'] ?? 'disabled'), $allow, true) ? $in['cron_winsen'] : 'disabled';
    $out['cron_empore']  = in_array(($in['cron_empore']  ?? 'disabled'), $allow, true) ? $in['cron_empore']  : 'disabled';
    $out['cron_winsen']  = in_array(($in['cron_winsen']  ?? 'disabled'), $allow, true) ? $in['cron_winsen']  : 'disabled';

    // Zeitpläne anwenden
    kse_reschedule_cron('kse_cron_miah',     $out['cron_miah']);
    kse_reschedule_cron('kse_cron_seevetal', $out['cron_seevetal']);
    kse_reschedule_cron('kse_cron_empore',   $out['cron_empore']);
    kse_reschedule_cron('kse_cron_winsen',   $out['cron_winsen']);

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

    if (!function_exists('kse_settings_render_page')) {
        function kse_settings_render_page() {

            if (isset($_GET['kse_fix_terms_done'])) {
            $checked = intval($_GET['checked'] ?? 0);
            $winsen  = intval($_GET['winsen_fixed'] ?? 0);
            $empore  = intval($_GET['empore_fixed'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p><strong>Kategorien repariert.</strong> Geprüft: '
                . $checked . ' &nbsp;|&nbsp; Winsen korrigiert: ' . $winsen . ' &nbsp;|&nbsp; Empore korrigiert: ' . $empore
                . '</p></div>';
        }


            echo '<div class="wrap"><h1>Einstellungen</h1>
            <form method="post" action="options.php">';
            settings_fields('kse_settings');
            do_settings_sections('kse_settings');
            submit_button();
            echo '</form></div>';
        }
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

add_action('kse_cron_empore', 'kse_cron_run_empore');
function kse_cron_run_empore() {
    if (!function_exists('kse_tec_upsert_event')) return;
    if (!class_exists('EmporeBuchholzParser')) return;

    $events = EmporeBuchholzParser::crawl(['limit'=>50]);
    if (!is_array($events)) return;

    $scanned = count($events); $created=$updated=$skipped=0;
    foreach ($events as $ev) {
        $u = $ev['source_url'] ?? ($ev['source'] ?? '');
        if (!$u) { $skipped++; continue; }
        $res = kse_tec_upsert_event([
            'title'       => $ev['title'] ?? '',
            'start'       => $ev['start'] ?? ($ev['datetime'] ?? ''),
            'end'         => $ev['end'] ?? '',
            'datetime'    => $ev['datetime'] ?? '',
            'description' => $ev['description'] ?? '',
            'location'    => $ev['location'] ?? '',
            'image'       => $ev['image'] ?? '',
            'source_url'  => $u,
        ], [
            'default_duration_minutes' => 120,
            'category'                 => 'Empore Buchholz',
        ]);
        $post_id = (int)($res['post_id'] ?? 0);
        if ($post_id > 0) {
            kse_force_event_category($post_id, 'Empore Buchholz', ['Gemeinde Seevetal']);
        }

        if (($res['action'] ?? '') === 'updated') $updated++;
        elseif (($res['action'] ?? '') === 'created') $created++;
        else $skipped++;
    }

    if (function_exists('kse_stats_log')) {
        kse_stats_log('EMPORE', compact('scanned','created','updated','skipped'));
    }
}

    // ---------- Kulturverein Winsen (ECHTER RUNNER) ----------
    add_action('kse_cron_winsen', 'kse_cron_run_winsen');
    function kse_cron_run_winsen() {
        if (!class_exists('KulturvereinWinsenParser') || !function_exists('kse_tec_upsert_event')) {
            if (function_exists('kse_stats_log')) {
                kse_stats_log('WINSEN', ['scanned'=>0,'created'=>0,'updated'=>0,'skipped'=>0,'blacklisted'=>0,'note'=>'Parser/Import-Helfer fehlen']);
            }
            return;
        }

        $events  = KulturvereinWinsenParser::crawl(['limit' => 0]);
        $scanned = is_array($events) ? count($events) : 0;
        $created = $updated = $skipped = 0;

        foreach ((array)$events as $ev) {
            $src = $ev['source_url'] ?? '';
            if (!$src) { $skipped++; continue; }

            $res = kse_tec_upsert_event([
                'title'       => $ev['title'] ?? '',
                'start'       => $ev['start'] ?? ($ev['datetime'] ?? ''),
                'description' => $ev['description'] ?? '',
                'location'    => $ev['location'] ?? '',
                'image'       => $ev['image'] ?? '',
                'source_url'  => $src,
            ], [
                'default_duration_minutes' => 120,
                'category'                 => 'Kulturverein Winsen',
            ]);
            // **NEU – Kategorie erzwingen & falsche entfernen**
            $post_id = (int)($res['post_id'] ?? 0);
            if ($post_id > 0) {
                kse_force_event_category($post_id, 'Kulturverein Winsen', ['Gemeinde Seevetal']);
            }

            $act = $res['action'] ?? '';
            if ($act === 'created')      { $created++; }
            elseif ($act === 'updated')  { $updated++; }
            else                         { $skipped++; }
        }

        if (function_exists('kse_stats_log')) {
            kse_stats_log('WINSEN', compact('scanned','created','updated','skipped') + ['blacklisted'=>0]);
        }
    }


// --- Admin-POST: Quelle sofort anstoßen (doppelt absichern) ---
if (!function_exists('kse_admin_run_source')) {
    add_action('admin_post_kse_run_source', 'kse_admin_run_source');
    function kse_admin_run_source() {
        if (!current_user_can('manage_options')) { wp_die('Insufficient permissions'); }
        check_admin_referer('kse_run_source');

        $src = isset($_GET['src']) ? sanitize_key($_GET['src']) : '';
        $map = [
            'miah'     => 'kse_cron_miah',
            'empore'   => 'kse_cron_empore',
            'winsen'   => 'kse_cron_winsen',
            'seevetal' => 'kse_cron_seevetal',
        ];

        if (isset($map[$src])) {
            do_action($map[$src]); // Parser sofort ausführen
            $redirect = wp_get_referer() ?: admin_url('admin.php?page=kse_settings');
            wp_safe_redirect(add_query_arg(['kse-run-done' => $src], $redirect));
            exit;
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=kse_settings'));
        exit;
    }
}

// Kleiner Helper für den Button-Link neben dem Select
if (!function_exists('kse_render_run_now_link')) {
    function kse_render_run_now_link($src, $label = 'Jetzt starten') {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=kse_run_source&src=' . $src),
            'kse_run_source'
        );
        echo '<a href="' . esc_url($url) . '" class="button button-secondary" style="margin-left:8px">' . esc_html($label) . '</a>';
    }
}
// Erzwingt eine bestimmte TEC-Kategorie und entfernt unerwünschte
if (!function_exists('kse_force_event_category')) {
    function kse_force_event_category(int $post_id, string $wanted_label, array $remove_labels = []) {
        $tax = 'tribe_events_cat';

        // Wunsch-Kategorie anlegen/holen
        $wanted = term_exists($wanted_label, $tax);
        if (!$wanted) {
            $new = wp_insert_term($wanted_label, $tax);
            if (is_wp_error($new)) return;
            $wanted_id = (int)$new['term_id'];
        } else {
            $wanted_id = (int)(is_array($wanted) ? $wanted['term_id'] : $wanted);
        }

        // Aktuelle Kategorien holen
        $current_terms = wp_get_object_terms($post_id, $tax, ['fields' => 'ids']);
        if (is_wp_error($current_terms)) $current_terms = [];
        $current_ids = array_map('intval', (array)$current_terms);

        // Unerwünschte entfernen
        $remove_ids = [];
        foreach ($remove_labels as $lab) {
            $t = term_exists($lab, $tax);
            if ($t) {
                $remove_ids[] = (int)(is_array($t) ? $t['term_id'] : $t);
            }
        }
        if ($remove_ids) {
            $current_ids = array_values(array_diff($current_ids, $remove_ids));
        }

        // Wunsch-Kategorie hinzufügen (falls fehlt)
        if (!in_array($wanted_id, $current_ids, true)) {
            $current_ids[] = $wanted_id;
        }

        // Zuweisen (ersetzen, nicht anhängen, damit Entfernen sicher wirkt)
        wp_set_object_terms($post_id, $current_ids, $tax, false);
    }
}
add_action('admin_post_kse_fix_terms', function () {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
    check_admin_referer('kse_fix_terms');

    $checked = 0; $winsen_fixed = 0; $empore_fixed = 0;

    // Hole jüngste 500 Events (anpassbar)
    $q = new WP_Query([
        'post_type'      => 'tribe_events',
        'posts_per_page' => 500,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'fields'         => 'ids',
    ]);

    if (!is_wp_error($q) && !empty($q->posts)) {
        foreach ($q->posts as $pid) {
            $checked++;
            $src = (string) get_post_meta($pid, '_EventURL', true);
            $content = (string) get_post_field('post_content', $pid);

            $is_winsen = (stripos($src, 'kv-winsen.') !== false) || (stripos($content, 'kv-winsen') !== false);
            $is_empore = (stripos($src, 'empore-buchholz') !== false) || (stripos($content, 'Empore') !== false);

            if ($is_winsen) {
                if (function_exists('kse_force_event_category')) {
                    kse_force_event_category($pid, 'Kulturverein Winsen', ['Gemeinde Seevetal']);
                }
                $winsen_fixed++;
            } elseif ($is_empore) {
                if (function_exists('kse_force_event_category')) {
                    kse_force_event_category($pid, 'Empore Buchholz', ['Gemeinde Seevetal']);
                }
                $empore_fixed++;
            }
        }
    }

    $redirect = wp_get_referer() ?: admin_url('admin.php?page=kse_settings');
    wp_safe_redirect(add_query_arg([
        'kse_fix_terms_done' => 1,
        'checked'            => $checked,
        'winsen_fixed'       => $winsen_fixed,
        'empore_fixed'       => $empore_fixed,
    ], $redirect));
    exit;
});
