<?php
if (!defined('ABSPATH')) exit;

/** Datum in WP-Lokalzeit normalisieren */
if (!function_exists('kse_normalize_datetime_local')) {
function kse_normalize_datetime_local($in) {
    if (empty($in)) $in = current_time('mysql');
    $ts = strtotime($in);
    if (!$ts) $ts = time();
    return date_i18n('Y-m-d H:i:s', $ts);
}}

if (!function_exists('kse_find_event_by_meta')) {
function kse_find_event_by_meta($key, $value) {
    $q = new WP_Query([
        'post_type'      => 'tribe_events',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'meta_query'     => [[ 'key' => $key, 'value' => $value, 'compare' => '=' ]],
        'fields'         => 'ids',
    ]);
    return $q->posts ? (int)$q->posts[0] : 0;
}}

if (!function_exists('kse_find_event_by_title_and_start')) {
function kse_find_event_by_title_and_start($title, $startLocal) {
    $q = new WP_Query([
        'post_type'      => 'tribe_events',
        'post_status'    => 'any',
        'posts_per_page' => 10,
        'no_found_rows'  => true,
        's'              => $title,
        'fields'         => 'ids',
        'meta_query'     => [[ 'key' => '_EventStartDate', 'value' => $startLocal, 'compare' => '=' ]],
    ]);
    return $q->posts ? (int)$q->posts[0] : 0;
}}

if (!function_exists('kse_get_or_create_event_cat')) {
function kse_get_or_create_event_cat($name) {
    if (!$name) return 0;
    $tax = 'tribe_events_cat';
    $t = term_exists($name, $tax);
    if (!$t || is_wp_error($t)) {
        $t = wp_insert_term($name, $tax);
        if (is_wp_error($t)) return 0;
    }
    return (int)($t['term_id'] ?? $t);
}}

/** bekannte Quell-Kategorien (erweiterbar per Filter) */
if (!function_exists('kse_known_source_categories')) {
function kse_known_source_categories() {
    $defaults = ['Gemeinde Seevetal', 'Musik in alten Heidekirchen'];
    $list = apply_filters('kse_known_source_categories', $defaults);
    $list = array_values(array_filter(array_map('strval', (array)$list)));
    return $list ?: $defaults;
}}

/** Schutzlogik für Updates: eigene / fremde Quelle */
if (!function_exists('kse_guard_update_policy')) {
function kse_guard_update_policy($post_id, $source_slug, $source_category) {
    // 1) „eigene Veranstaltungen“ NIE ändern (Kategorie ODER Tag)
    $own_label = apply_filters('kse_own_protect_term', 'eigene Veranstaltungen');
    if ($own_label) {
        if (has_term($own_label, 'tribe_events_cat', $post_id) || has_term($own_label, 'post_tag', $post_id)) {
            return ['blocked' => true, 'reason' => 'own_protected'];
        }
    }

    // 2) Meta-Marker prüfen
    $marker = (string)get_post_meta($post_id, '_kse_source_slug', true);
    if ($marker !== '' && $source_slug !== '' && $marker !== $source_slug) {
        return ['blocked' => true, 'reason' => 'foreign_source_meta', 'marker' => $marker];
    }

    // 3) Kategorien prüfen (nur wenn eine der bekannten Quell-Kategorien vorhanden ist)
    $known = array_map('mb_strtolower', kse_known_source_categories());
    $event_terms = wp_get_object_terms($post_id, 'tribe_events_cat', ['fields' => 'names']);
    $event_lower = array_map('mb_strtolower', (array)$event_terms);
    $has_any_known = (bool) array_intersect($known, $event_lower);

    if ($has_any_known) {
        $current_lower = mb_strtolower((string)$source_category);
        if ($current_lower && !in_array($current_lower, $event_lower, true)) {
            return ['blocked' => true, 'reason' => 'foreign_source_category'];
        }
    }

    return ['blocked' => false];
}}

/** Bild setzen (idempotent) */
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

/**
 * UPSERT in TEC mit Quell-Schutz
 * $payload: ['title','description','start','location','image','source_url']
 * $opts: ['default_duration_minutes'=>int, 'source_slug'=>string, 'source_category'=>string]
 *
 * Rückgabe: ['action'=>created|updated|skipped_*|error, 'post_id'=>int, 'permalink'=>string, 'reason'=>string?]
 */
if (!function_exists('kse_tec_upsert_event')) {
function kse_tec_upsert_event(array $payload, array $opts = []) {

    $title   = trim((string)($payload['title'] ?? ''));
    $desc    = (string)($payload['description'] ?? '');
    $start   = kse_normalize_datetime_local($payload['start'] ?? ($payload['datetime'] ?? ''));
    $loc     = trim((string)($payload['location'] ?? ''));
    $image   = esc_url_raw($payload['image'] ?? '');
    $srcUrl  = esc_url_raw($payload['source_url'] ?? ($payload['source'] ?? ''));

    $duration       = (int)($opts['default_duration_minutes'] ?? 120);
    if ($duration < 15) $duration = 120;
    $source_slug    = trim((string)($opts['source_slug'] ?? ''));
    $source_catname = trim((string)($opts['source_category'] ?? ''));

    $endTs   = strtotime($start) + $duration * 60;
    $end     = date_i18n('Y-m-d H:i:s', $endTs);

    // 1) Event wiederfinden (idempotent)
    $post_id = 0;
    if ($srcUrl) {
        $post_id = kse_find_event_by_meta('_kse_source_url', $srcUrl);
        if (!$post_id) $post_id = kse_find_event_by_meta('_EventURL', $srcUrl);
    }
    if (!$post_id && $title) {
        $post_id = kse_find_event_by_title_and_start($title, $start);
    }

    // 2) Schutz prüfen, falls es das Event schon gibt
    if ($post_id) {
        $guard = kse_guard_update_policy($post_id, $source_slug, $source_catname);
        if (!empty($guard['blocked'])) {
            return [
                'action'    => 'skipped_'.$guard['reason'],
                'post_id'   => $post_id,
                'permalink' => get_permalink($post_id),
                'reason'    => $guard['reason'],
            ];
        }
    }

    // 3) Insert oder Update
    $postarr = [
        'post_type'    => 'tribe_events',
        'post_title'   => $title ?: '(ohne Titel)',
        'post_content' => $desc,
        'post_status'  => 'publish',
    ];

    if ($post_id) {
        $postarr['ID'] = $post_id;
        $post_id = wp_update_post($postarr, true);
        $action  = 'updated';
    } else {
        $slug = sanitize_title($title.'-'.substr(md5($srcUrl ?: $title.$start),0,8));
        $postarr['post_name'] = $slug;
        $post_id = wp_insert_post($postarr, true);
        $action  = 'created';
    }

    if (is_wp_error($post_id) || !$post_id) {
        return ['action'=>'error','error'=> is_wp_error($post_id) ? $post_id->get_error_message() : 'insert_failed'];
    }

    // 4) Metadaten
    update_post_meta($post_id, '_EventStartDate', $start);
    update_post_meta($post_id, '_EventEndDate',   $end);
    if ($srcUrl) {
        update_post_meta($post_id, '_kse_source_url', $srcUrl);
        update_post_meta($post_id, '_EventURL',       $srcUrl);
    }
    if ($source_slug !== '') {
        update_post_meta($post_id, '_kse_source_slug', $source_slug);
    }
    if ($loc) {
        update_post_meta($post_id, '_kse_location_raw', $loc);
        $content = get_post_field('post_content', $post_id);
        if ($content && strpos($content, $loc) === false) {
            wp_update_post(['ID'=>$post_id, 'post_content' => $content . "\n\n<p><strong>Ort:</strong> ".esc_html($loc)."</p>"]);
        }
    }

    // 5) Kategorie der Quelle setzen/ergänzen (ohne fremde zu entfernen)
    if ($source_catname) {
        $term_id = kse_get_or_create_event_cat($source_catname);
        if ($term_id) wp_set_object_terms($post_id, [$term_id], 'tribe_events_cat', true);
    }

    // 6) Bild (idempotent)
    if ($image) {
        kse_attach_image_to_post($image, $post_id, $title);
    }

    return [
        'action'    => $action,
        'post_id'   => $post_id,
        'permalink' => get_permalink($post_id),
    ];
}}
