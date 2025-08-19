<?php
if (!defined('ABSPATH')) exit;

/**
 * TEC Import-Utilities (WP + The Events Calendar Pro 7.7.0)
 *
 * Public API:
 *   kse_tec_upsert_event(array $payload, array $opts = []) : array
 *
 * $payload: [
 *   'title'       => string,
 *   'description' => string (HTML erlaubt),
 *   'start'       => 'Y-m-d H:i:s' (lokale WP-Zeit) oder beliebig parsebar,
 *   'end'         => 'Y-m-d H:i:s' (optional, s.o.),
 *   'location'    => string (optional),
 *   'image'       => string URL (optional),
 *   'source_url'  => string URL (optional, für Idempotenz & Quelle),
 * ]
 *
 * $opts: [
 *   'default_duration_minutes' => int (Standard 120),
 *   'source_slug'              => string (z.B. 'empore'),
 *   'source_category'          => string (z.B. 'Empore Buchholz'),
 * ]
 *
 * Rückgabe:
 *   ['action'=>'created'|'updated'|'skipped_*'|'error', 'post_id'=>int, 'permalink'=>string, 'reason'?(string)]
 */


/* ============================ Helpers ============================ */

/** Normiert Datum/Zeit in lokale WP-Zeit (Y-m-d H:i:s) */
if (!function_exists('kse_normalize_datetime_local')) {
function kse_normalize_datetime_local($in) {
    $in = (string)$in;
    if ($in === '') return date_i18n('Y-m-d H:i:s', current_time('timestamp'));
    $ts = strtotime($in);
    if (!$ts) $ts = current_time('timestamp');
    return date_i18n('Y-m-d H:i:s', $ts);
}}

/** Post per exaktem Meta finden */
if (!function_exists('kse_find_event_by_meta')) {
function kse_find_event_by_meta($key, $value) {
    $q = new WP_Query([
        'post_type'      => 'tribe_events',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'meta_query'     => [[ 'key'=>$key, 'value'=>$value ]],
        'fields'         => 'ids',
    ]);
    return $q->have_posts() ? (int)$q->posts[0] : 0;
}}

/** Post per (Title + Start) finden */
if (!function_exists('kse_find_event_by_title_and_start')) {
function kse_find_event_by_title_and_start($title, $start) {
    $title = trim((string)$title);
    $start = kse_normalize_datetime_local($start);
    if ($title === '' || $start === '') return 0;

    $q = new WP_Query([
        'post_type'      => 'tribe_events',
        'post_status'    => 'any',
        'posts_per_page' => 25,
        'no_found_rows'  => true,
        'meta_query'     => [[ 'key'=>'_EventStartDate', 'value'=>$start ]],
        'fields'         => 'ids',
    ]);
    if (!$q->have_posts()) return 0;
    foreach ($q->posts as $pid) {
        if (strcasecmp(get_the_title($pid), $title) === 0) return (int)$pid;
    }
    return 0;
}}

/** "Darf überschrieben werden?" – einfacher Guard */
if (!function_exists('kse_can_overwrite_event')) {
function kse_can_overwrite_event($post_id, $source_slug = '') {
    if (get_post_meta($post_id, '_kse_protect', true)) {
        return ['blocked'=>true, 'reason'=>'protected_flag'];
    }
    $foreign = get_post_meta($post_id, '_kse_source_slug', true);
    if ($foreign && $source_slug && $foreign !== $source_slug) {
        return ['blocked'=>true, 'reason'=>'different_source'];
    }
    return ['blocked'=>false];
}}

/** Event-Kategorie holen/erstellen (tribe_events_cat) */
if (!function_exists('kse_get_or_create_event_cat')) {
function kse_get_or_create_event_cat($name) {
    $name = trim((string)$name);
    if ($name === '') return 0;
    $tax = 'tribe_events_cat';
    $t = term_exists($name, $tax);
    if (!$t || is_wp_error($t)) {
        $t = wp_insert_term($name, $tax);
        if (is_wp_error($t)) return 0;
    }
    return (int)($t['term_id'] ?? $t);
}}

/** Bild per URL anheften (featured image) – idempotent */
if (!function_exists('kse_attach_image_to_post')) {
function kse_attach_image_to_post($imageUrl, $post_id, $desc = '') {
    $imageUrl = esc_url_raw((string)$imageUrl);
    if (!$imageUrl || !$post_id) return 0;
    $prev = get_post_meta($post_id, '_kse_image_src', true);
    if ($prev && $prev === $imageUrl && has_post_thumbnail($post_id)) {
        return (int)get_post_thumbnail_id($post_id);
    }
    require_once ABSPATH.'wp-admin/includes/media.php';
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/image.php';
    $att_id = media_sideload_image($imageUrl, $post_id, $desc, 'id');
    if (is_wp_error($att_id)) return 0;
    set_post_thumbnail($post_id, $att_id);
    update_post_meta($post_id, '_kse_image_src', esc_url_raw($imageUrl));
    return (int)$att_id;
}}

/** TEC-Indizierung/Cache nachziehen (ohne Editor) */
if (!function_exists('kse_trigger_tec_index')) {
function kse_trigger_tec_index($post_id, $is_update) {
    $post = get_post($post_id);

    // Simuliere Editor-Save-Hooks
    do_action('save_post',              $post_id, $post, $is_update);
    do_action('save_post_tribe_events', $post_id, $post, $is_update);

    // TEC 6+: CT1 Index pflegen (wenn verfügbar)
    try {
        if (function_exists('tribe')) {
            if (class_exists('\\TEC\\Events\\Custom_Tables\\V1\\Manager')) {
                $mgr = tribe(\TEC\Events\Custom_Tables\V1\Manager::class);
                if (method_exists($mgr, 'rebuild_index_for_post')) {
                    $mgr->rebuild_index_for_post($post_id);
                }
            }
            if (class_exists('\\TEC\\Events\\Custom_Tables\\V1\\WP\\Query')) {
                $q = tribe(\TEC\Events\Custom_Tables\V1\WP\Query::class);
                if (method_exists($q, 'reset_cache')) $q->reset_cache();
            }
        }
    } catch (\Throwable $e) {
        // still fine
    }

    clean_post_cache($post_id);
    wp_cache_delete($post_id, 'posts');
    wp_cache_delete($post_id, 'post_meta');
}}


/* ============================ Main API ============================ */

/**
 * Upsert eines Events in TEC (ohne manuelles "Aktualisieren" im Editor).
 */
if (!function_exists('kse_tec_upsert_event')) {
function kse_tec_upsert_event(array $payload, array $opts = []) {

    $title  = trim((string)($payload['title'] ?? ''));
    $desc   = (string)($payload['description'] ?? '');
    $start  = kse_normalize_datetime_local($payload['start'] ?? '');
    $endIn  = (string)($payload['end'] ?? '');
    $loc    = trim((string)($payload['location'] ?? ''));
    $image  = trim((string)($payload['image'] ?? ''));
    $srcUrl = esc_url_raw((string)($payload['source_url'] ?? ''));

    $source_slug    = sanitize_key((string)($opts['source_slug'] ?? ''));
    $source_catname = trim((string)($opts['source_category'] ?? ''));
    $duration       = (int)($opts['default_duration_minutes'] ?? 120);

    if ($title === '') return ['action'=>'error', 'reason'=>'missing_title'];
    if ($start === '') $start = date_i18n('Y-m-d H:i:s', current_time('timestamp'));

    // Endzeit bestimmen
    $end = '';
    if ($endIn !== '') {
        $end = kse_normalize_datetime_local($endIn);
    } else {
        $endTs = strtotime($start) + max(1, $duration) * 60;
        $end   = date_i18n('Y-m-d H:i:s', $endTs);
    }

    // 1) Existierenden Event finden (Idempotenz)
    $post_id = 0;
    if ($srcUrl) {
        $post_id = kse_find_event_by_meta('_kse_source_url', $srcUrl);
        if (!$post_id) $post_id = kse_find_event_by_meta('_EventURL', $srcUrl);
    }
    if (!$post_id) {
        $maybe = kse_find_event_by_title_and_start($title, $start);
        if ($maybe) $post_id = $maybe;
    }

    // 2) Guard
    if ($post_id) {
        $guard = kse_can_overwrite_event($post_id, $source_slug);
        if (!empty($guard['blocked'])) {
            return [
                'action'    => 'skipped_guard',
                'post_id'   => $post_id,
                'permalink' => get_permalink($post_id),
                'reason'    => $guard['reason'] ?? 'guard',
            ];
        }
    }

    $is_update = false;

    // 3) INSERT über ORM (TEC 6+) – kein erzwungener Slug!
    if (!$post_id) {
        $created = false;

        // Bevorzugt: tec_events() (neuere Helper), sonst tribe_events()
        if (function_exists('tec_events')) {
            try {
                $created = tec_events()
                    ->set_args([
                        'title'       => ($title ?: '(ohne Titel)'),
                        'description' => $desc,
                        'status'      => 'publish',
                        'start_date'  => $start,
                        'end_date'    => $end,
                        'url'         => $srcUrl ?: null,
                    ])
                    ->create(); // WP_Post|false
            } catch (\Throwable $e) {
                $created = false;
            }
        }

        if (!$created && function_exists('tribe_events')) {
            try {
                $created = tribe_events()
                    ->set_args([
                        'title'       => ($title ?: '(ohne Titel)'),
                        'description' => $desc,
                        'status'      => 'publish',
                        'start_date'  => $start,
                        'end_date'    => $end,
                        'url'         => $srcUrl ?: null,
                    ])
                    ->create(); // WP_Post|false
            } catch (\Throwable $e) {
                $created = false;
            }
        }

        if ($created instanceof WP_Post) {
            $post_id = (int)$created->ID;
        } else {
            // Fallback: klassisch einfügen (für sehr alte Builds)
            $res = wp_insert_post([
                'post_type'    => 'tribe_events',
                'post_title'   => ($title ?: '(ohne Titel)'),
                'post_content' => $desc,
                'post_status'  => 'publish',
            ], true);
            if (is_wp_error($res)) return ['action'=>'error', 'reason'=>$res->get_error_message()];
            $post_id = (int)$res;
        }

    // 3b) UPDATE – Titel/Inhalt aktualisieren (Slug unangetastet)
    } else {
        $res = wp_update_post([
            'ID'           => $post_id,
            'post_title'   => ($title ?: '(ohne Titel)'),
            'post_content' => $desc,
            'post_status'  => 'publish',
        ], true);
        if (is_wp_error($res)) return ['action'=>'error', 'reason'=>$res->get_error_message()];
        $is_update = true;

        // Per TEC-API sicherstellen, dass Datumswerte & Index stimmen
        if (class_exists('Tribe__Events__API')) {
            try {
                Tribe__Events__API::update_event($post_id, [
                    'post_status' => 'publish',
                    'start_date'  => $start,
                    'end_date'    => $end,
                ]);
            } catch (\Throwable $e) {
                // Fallback: direkte Meta-Keys
                update_post_meta($post_id, '_EventStartDate',    $start);
                update_post_meta($post_id, '_EventEndDate',      $end);
                update_post_meta($post_id, '_EventStartDateUTC', get_gmt_from_date($start));
                update_post_meta($post_id, '_EventEndDateUTC',   get_gmt_from_date($end));
                update_post_meta($post_id, '_EventDuration',     max(1, strtotime($end) - strtotime($start)));
            }
        }
    }

    // 4) Source-Metas & Datums-Fallbacks (falls ORM/API nicht alle setzt)
    if ($srcUrl) {
        update_post_meta($post_id, '_kse_source_url', $srcUrl);
        update_post_meta($post_id, '_EventURL',       $srcUrl);
    }
    // Date-Metas (failsafe)
    if (!get_post_meta($post_id, '_EventStartDate', true)) {
        update_post_meta($post_id, '_EventStartDate',    $start);
        update_post_meta($post_id, '_EventEndDate',      $end);
        update_post_meta($post_id, '_EventStartDateUTC', get_gmt_from_date($start));
        update_post_meta($post_id, '_EventEndDateUTC',   get_gmt_from_date($end));
        update_post_meta($post_id, '_EventDuration',     max(1, strtotime($end) - strtotime($start)));
    }

    // 5) Source-Tag
    if ($source_slug !== '') {
        update_post_meta($post_id, '_kse_source_slug', $source_slug);
    }

    // 6) Ort idempotent anhängen (wie von dir gewünscht)
    if ($loc !== '') {
        update_post_meta($post_id, '_kse_location_raw', $loc);
        $content = get_post_field('post_content', $post_id);
        if ($content && mb_stripos($content, $loc) === false) {
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $content . "\n\n<p><strong>Ort:</strong> " . esc_html($loc) . "</p>",
            ]);
        }
    }

    // 7) Quelle idempotent anhängen
    if ($srcUrl) {
        $cur = get_post_field('post_content', $post_id);
        if ($cur && stripos($cur, 'Quelle: (C)') === false) {
            $append = "\n\n<p><em>Quelle: (C) <a href=\"" . esc_url($srcUrl) . "\" target=\"_blank\" rel=\"noopener\">" . esc_html($srcUrl) . "</a></em></p>";
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $cur . $append,
            ]);
        }
    }

    // 8) Kategorie setzen/anhängen
    if ($source_catname !== '') {
        $term_id = kse_get_or_create_event_cat($source_catname);
        if ($term_id) wp_set_object_terms($post_id, [$term_id], 'tribe_events_cat', true);
    }

    // 9) Bild anheften
    if ($image) {
        kse_attach_image_to_post($image, $post_id, $title);
    }

    // 10) TEC-Index/Caches aktualisieren -> Permalink sofort funktionsfähig (keine 404)
    kse_trigger_tec_index($post_id, $is_update);

    return [
        'action'    => $is_update ? 'updated' : 'created',
        'post_id'   => $post_id,
        'permalink' => get_permalink($post_id),
    ];
}}
