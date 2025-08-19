<?php
if (!defined('ABSPATH')) exit;

/**
 * Utilities for importing/upserting events into The Events Calendar (TEC)
 * without requiring manual "Update" in the editor.
 *
 * This file is intentionally self-contained. All requires are handled upstream.
 */

/** Format a datetime string in WP local time (Y-m-d H:i:s) */
if (!function_exists('kse_normalize_datetime_local')) {
function kse_normalize_datetime_local($in) {
    if (empty($in)) $in = current_time('mysql');
    $ts = strtotime($in);
    if (!$ts) $ts = time();
    return date_i18n('Y-m-d H:i:s', $ts);
}}
/** Find an event by a specific meta key/value (exact match). */
if (!function_exists('kse_find_event_by_meta')) {
function kse_find_event_by_meta($key, $value) {
    $q = new WP_Query([
        'post_type'      => 'tribe_events',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'meta_query'     => [
            [
                'key'   => $key,
                'value' => $value,
            ]
        ],
        'fields' => 'ids',
    ]);
    return $q->have_posts() ? (int)$q->posts[0] : 0;
}}
/** Find an event by exact title and start datetime. */
if (!function_exists('kse_find_event_by_title_and_start')) {
function kse_find_event_by_title_and_start($title, $start) {
    $title = trim((string)$title);
    $start = kse_normalize_datetime_local($start);
    if ($title === '' || $start === '') return 0;

    $q = new WP_Query([
        'post_type'      => 'tribe_events',
        'post_status'    => 'any',
        'posts_per_page' => 30,
        'no_found_rows'  => true,
        'meta_query'     => [
            [
                'key'   => '_EventStartDate',
                'value' => $start,
            ]
        ],
        'fields' => 'ids',
    ]);
    if (!$q->have_posts()) return 0;
    foreach ($q->posts as $pid) {
        if (strcasecmp(get_the_title($pid), $title) === 0) {
            return (int)$pid;
        }
    }
    return 0;
}}
/** Very small guard: allow skipping updates on protected posts. */
if (!function_exists('kse_can_overwrite_event')) {
function kse_can_overwrite_event($post_id, $source_slug = '') {
    // If someone set a manual protection flag, never overwrite
    $protected = get_post_meta($post_id, '_kse_protect', true);
    if ($protected) return ['blocked'=>true, 'reason'=>'protected_flag'];

    // If post has a different source and is protected by category, skip
    $foreign = get_post_meta($post_id, '_kse_source_slug', true);
    if ($foreign && $source_slug && $foreign !== $source_slug) {
        // optional: only block if it has a known source category that is not ours
        $cats = wp_get_post_terms($post_id, 'tribe_events_cat', ['fields'=>'names']);
        $cats = array_map('strval', (array)$cats);
        if (!empty($cats)) {
            return ['blocked'=>true, 'reason'=>'different_source'];
        }
    }
    return ['blocked'=>false];
}}
/** Get or create an event category by name. */
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
/** Attach an image by URL and set as featured image (idempotent). */
if (!function_exists('kse_attach_image_to_post')) {
function kse_attach_image_to_post($imageUrl, $post_id, $desc = '') {
    if (!$imageUrl || !$post_id) return 0;
    $prev = get_post_meta($post_id, '_kse_image_src', true);
    if ($prev && $prev === $imageUrl && has_post_thumbnail($post_id)) return (int)get_post_thumbnail_id($post_id);

    require_once ABSPATH.'wp-admin/includes/media.php';
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/image.php';
    $att_id = media_sideload_image($imageUrl, $post_id, $desc, 'id');
    if (is_wp_error($att_id)) return 0;
    set_post_thumbnail($post_id, $att_id);
    update_post_meta($post_id, '_kse_image_src', esc_url_raw($imageUrl));
    return (int)$att_id;
}}
/** Internal: trigger TEC indexing/hooks so permalinks work immediately. */
if (!function_exists('kse_trigger_tec_index')) {
function kse_trigger_tec_index($post_id, $is_update) {
    $post = get_post($post_id);

    // Simulate the hooks that fire when saving in the editor
    do_action('save_post',               $post_id, $post, $is_update);
    do_action('save_post_tribe_events',  $post_id, $post, $is_update);

    // TEC 6 Custom Tables index maintenance (if available)
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
        // ignore
    }

    clean_post_cache($post_id);
    wp_cache_delete($post_id, 'posts');
    wp_cache_delete($post_id, 'post_meta');
}}
/**
 * Upsert into TEC with source-guard.
 * $payload: ['title','description','start','end?','location','image','source_url']
 * $opts:    ['default_duration_minutes'=>int, 'source_slug'=>string, 'source_category'=>string]
 *
 * Return: ['action'=>created|updated|skipped_*|error, 'post_id'=>int, 'permalink'=>string, 'reason'?]
 */
if (!function_exists('kse_tec_upsert_event')) {
function kse_tec_upsert_event(array $payload, array $opts = []) {
    $title   = trim((string)($payload['title'] ?? ''));
    $desc    = (string)($payload['description'] ?? '');
    $start   = kse_normalize_datetime_local($payload['start'] ?? '');
    $endIn   = (string)($payload['end'] ?? '');
    $loc     = trim((string)($payload['location'] ?? ''));
    $image   = trim((string)($payload['image'] ?? ''));
    $srcUrl  = esc_url_raw((string)($payload['source_url'] ?? ''));

    $source_slug     = sanitize_key((string)($opts['source_slug'] ?? ''));
    $source_catname  = trim((string)($opts['source_category'] ?? ''));
    $duration        = (int)($opts['default_duration_minutes'] ?? 120);

    if ($title === '') return ['action'=>'error', 'reason'=>'missing_title'];
    if ($start === '') $start = current_time('mysql');

    $end = '';
    if ($endIn !== '') {
        $end = kse_normalize_datetime_local($endIn);
    } else {
        $endTs = strtotime($start) + max(1, $duration) * 60;
        $end   = date_i18n('Y-m-d H:i:s', $endTs);
    }

    // 1) Find existing (idempotent)
    $post_id = 0;
    if ($srcUrl) {
        $post_id = kse_find_event_by_meta('_kse_source_url', $srcUrl);
        if (!$post_id) $post_id = kse_find_event_by_meta('_EventURL', $srcUrl);
    }
    if (!$post_id) {
        $maybe = kse_find_event_by_title_and_start($title, $start);
        if ($maybe) $post_id = $maybe;
    }

    // 2) Guard (skip if protected)
    if ($post_id) {
        $guard = kse_can_overwrite_event($post_id, $source_slug);
        if (!empty($guard['blocked'])) {
            return [
                'action' => 'skipped_guard',
                'post_id'=> $post_id,
                'permalink' => get_permalink($post_id),
                'reason' => $guard['reason'] ?? 'guard',
            ];
        }
    }

    // 3) Insert / Update (do not set post_name; let WP create a nice unique slug)
    $postarr = [
        'post_type'    => 'tribe_events',
        'post_title'   => $title ?: '(ohne Titel)',
        'post_content' => $desc,
        'post_status'  => 'publish',
    ];

    $action = 'created';
    if ($post_id) {
        $postarr['ID'] = $post_id;
        $res = wp_update_post($postarr, true);
        if (is_wp_error($res)) return ['action'=>'error', 'reason'=>$res->get_error_message()];
        $post_id = (int)$res;
        $action  = 'updated';
    } else {
        $res = wp_insert_post($postarr, true);
        if (is_wp_error($res)) return ['action'=>'error', 'reason'=>$res->get_error_message()];
        $post_id = (int)$res;
        $action  = 'created';
    }

    // 4) Set TEC dates via API if available (ensures indexing), else meta fallback
    if (class_exists('Tribe__Events__API')) {
        try {
            Tribe__Events__API::update_event($post_id, [
                'post_status' => 'publish',
                'start_date'  => $start,
                'end_date'    => $end,
            ]);
        } catch (\Throwable $e) {
            update_post_meta($post_id, '_EventStartDate',    $start);
            update_post_meta($post_id, '_EventEndDate',      $end);
            update_post_meta($post_id, '_EventStartDateUTC', get_gmt_from_date($start));
            update_post_meta($post_id, '_EventEndDateUTC',   get_gmt_from_date($end));
            update_post_meta($post_id, '_EventDuration',     max(1, strtotime($end) - strtotime($start)));
        }
    } else {
        update_post_meta($post_id, '_EventStartDate',    $start);
        update_post_meta($post_id, '_EventEndDate',      $end);
        update_post_meta($post_id, '_EventStartDateUTC', get_gmt_from_date($start));
        update_post_meta($post_id, '_EventEndDateUTC',   get_gmt_from_date($end));
        update_post_meta($post_id, '_EventDuration',     max(1, strtotime($end) - strtotime($start)));
    }

    // 5) Source metas
    if ($srcUrl) {
        update_post_meta($post_id, '_kse_source_url', $srcUrl);
        update_post_meta($post_id, '_EventURL',       $srcUrl);
    }
    if ($source_slug !== '') {
        update_post_meta($post_id, '_kse_source_slug', $source_slug);
    }

    // 6) Append location once (as requested)
    if ($loc) {
        update_post_meta($post_id, '_kse_location_raw', $loc);
        $content = get_post_field('post_content', $post_id);
        if ($content && mb_stripos($content, $loc) === false) {
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $content . "\n\n<p><strong>Ort:</strong> " . esc_html($loc) . "</p>",
            ]);
        }
    }

    // 7) Append "Quelle" line once (idempotent)
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

    // 8) Source category (append)
    if ($source_catname) {
        $term_id = kse_get_or_create_event_cat($source_catname);
        if ($term_id) wp_set_object_terms($post_id, [$term_id], 'tribe_events_cat', true);
    }

    // 9) Featured image (idempotent)
    if ($image) {
        kse_attach_image_to_post($image, $post_id, $title);
    }

    // 10) Trigger TEC indexing/caches so permalink works immediately
    kse_trigger_tec_index($post_id, $action === 'updated');

    return [
        'action'    => $action,
        'post_id'   => $post_id,
        'permalink' => get_permalink($post_id),
    ];
}}
