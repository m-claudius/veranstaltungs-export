<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('kse_norm_title')) {
function kse_norm_title($t){
    $t = wp_strip_all_tags((string)$t);
    $t = mb_strtolower($t, 'UTF-8');
    $t = preg_replace('/[^a-z0-9äöüß ]+/u',' ', $t);
    $t = preg_replace('/\s+/',' ', $t);
    return trim($t);
}}

if (!function_exists('kse_find_duplicates_groups')) {
function kse_find_duplicates_groups($days_back = 120){
    $groups = [];
    $q = new WP_Query([
        'post_type'      => 'tribe_events',
        'post_status'    => 'publish',
        'posts_per_page' => 500,
        'no_found_rows'  => true,
        'date_query'     => [
            'after' => date_i18n('Y-m-d', strtotime('-'.intval($days_back).' days'))
        ],
        'fields' => 'ids',
    ]);
    foreach ($q->posts as $pid) {
        $title = get_the_title($pid);
        $start = get_post_meta($pid, '_EventStartDate', true);
        $norm  = kse_norm_title($title);
        $key   = $norm.'|'.$start;
        if (!$norm || !$start) continue;
        $groups[$key][] = $pid;
    }
    // nur Gruppen mit mehr als 1
    return array_filter($groups, function($arr){ return count($arr) > 1; });
}}

if (!function_exists('kse_merge_event_group')) {
function kse_merge_event_group(array $ids){
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) < 2) return ['merged'=>false,'primary'=>0,'trashed'=>[]];

    // Primary wählen: mit Bild zuerst, sonst ältester
    usort($ids, function($a,$b){
        $hasA = has_post_thumbnail($a) ? 1 : 0;
        $hasB = has_post_thumbnail($b) ? 1 : 0;
        if ($hasA !== $hasB) return $hasB - $hasA;
        $tA = get_post_time('U', true, $a);
        $tB = get_post_time('U', true, $b);
        return $tA <=> $tB; // ältester zuerst
    });
    $primary = array_shift($ids);

    // Inhalte/Terms/META vereinigen
    $content = get_post_field('post_content', $primary);
    $terms   = wp_get_object_terms($primary, 'tribe_events_cat', ['fields'=>'ids']);
    $trashed = [];

    foreach ($ids as $dup) {
        // längere Beschreibung übernehmen/anhängen
        $c2 = get_post_field('post_content', $dup);
        if (strlen(wp_strip_all_tags($c2)) > strlen(wp_strip_all_tags($content))) {
            $content = $c2;
        }

        // Bild nachrüsten, wenn primary keins hat
        if (!has_post_thumbnail($primary) && has_post_thumbnail($dup)) {
            set_post_thumbnail($primary, get_post_thumbnail_id($dup));
        }

        // Kategorien vereinigen
        $t2 = wp_get_object_terms($dup, 'tribe_events_cat', ['fields'=>'ids']);
        $terms = array_values(array_unique(array_merge($terms ?: [], $t2 ?: [])));

        // zusätzliche Quellen sammeln
        $srcDup = get_post_meta($dup, '_kse_source_url', true);
        if ($srcDup) {
            $others = (array) get_post_meta($primary, '_kse_other_sources', true);
            $others[] = $srcDup;
            update_post_meta($primary, '_kse_other_sources', array_values(array_unique($others)));
        }

        // Quell-Kennungen an den Primary übernehmen, falls er keine hat -
        // sonst findet der nächste Crawler-Lauf ihn nicht und legt neu an.
        foreach (['_kse_source_url', '_kse_source_uid', '_kse_source_slug'] as $meta_key) {
            if (!get_post_meta($primary, $meta_key, true)) {
                $val = get_post_meta($dup, $meta_key, true);
                if ($val) update_post_meta($primary, $meta_key, $val);
            }
        }

        // Duplikat in den Papierkorb
        wp_trash_post($dup);
        $trashed[] = $dup;
    }

    // final updaten
    wp_update_post(['ID'=>$primary,'post_content'=>$content]);
    if (!empty($terms)) wp_set_object_terms($primary, $terms, 'tribe_events_cat', false);

    // UID sicherstellen (Alt-Bestand ohne _kse_source_uid)
    $src = get_post_meta($primary, '_kse_source_url', true);
    if ($src && function_exists('kse_ei_backfill_uid')) {
        kse_ei_backfill_uid($primary, (string)$src, (string)get_post_meta($primary, '_kse_source_slug', true));
    }

    return ['merged'=>true,'primary'=>$primary,'trashed'=>$trashed];
}}

/* =========================================================
 * Identitäts-basierte Gruppierung (ersetzt die Titel+Start-Heuristik)
 * =======================================================*/

if (!function_exists('kse_collect_event_rows')) {
/**
 * Alle importierten Events mit ihren Kennungen - eine Abfrage, ohne WP_Query.
 *
 * @param bool $include_trash Papierkorb mitnehmen (für die Diagnose nützlich)
 * @return array<int,array>   [ID => ['id','title','status','date','uid','src','slug','start']]
 */
function kse_collect_event_rows(bool $include_trash = false, int $limit = 5000): array
{
    global $wpdb;

    $statuses = $include_trash
        ? "'publish','draft','pending','private','future','trash'"
        : "'publish','draft','pending','private','future'";

    $sql = "SELECT p.ID, p.post_title, p.post_status, p.post_date,
                   MAX(CASE WHEN m.meta_key = '_kse_source_uid'  THEN m.meta_value END) AS uid,
                   MAX(CASE WHEN m.meta_key = '_kse_source_url'  THEN m.meta_value END) AS src,
                   MAX(CASE WHEN m.meta_key = '_kse_source_slug' THEN m.meta_value END) AS slug,
                   MAX(CASE WHEN m.meta_key = '_EventStartDate'  THEN m.meta_value END) AS start
              FROM {$wpdb->posts} p
              LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type = 'tribe_events'
               AND p.post_status IN ({$statuses})
             GROUP BY p.ID, p.post_title, p.post_status, p.post_date
             ORDER BY p.ID ASC
             LIMIT %d";

    $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $limit));

    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r->ID] = [
            'id'     => (int)$r->ID,
            'title'  => (string)$r->post_title,
            'status' => (string)$r->post_status,
            'date'   => (string)$r->post_date,
            'uid'    => (string)($r->uid ?? ''),
            'src'    => (string)($r->src ?? ''),
            'slug'   => (string)($r->slug ?? ''),
            'start'  => (string)($r->start ?? ''),
        ];
    }
    return $out;
}}

if (!function_exists('kse_identity_key_for_row')) {
/**
 * Gruppierungsschlüssel eines Events:
 * vorhandene UID > UID aus der Quell-URL > normalisierter Titel + Startdatum.
 */
function kse_identity_key_for_row(array $row): string
{
    if ($row['uid'] !== '') return $row['uid'];

    if ($row['src'] !== '' && function_exists('kse_ei_source_uid')) {
        $uid = kse_ei_source_uid($row['src'], $row['slug']);
        if ($uid !== '') return $uid;
    }

    if (function_exists('kse_ei_norm_title')) {
        $norm = kse_ei_norm_title($row['title']);
        if ($norm !== '' && $row['start'] !== '') {
            return 'titel:' . $norm . '|' . $row['start'];
        }
    }
    return '';
}}

if (!function_exists('kse_find_duplicate_groups_by_identity')) {
/**
 * Liefert alle Gruppen mit mehr als einem Event.
 *
 * @return array<string,int[]> Schlüssel => Post-IDs (aufsteigend)
 */
function kse_find_duplicate_groups_by_identity(bool $include_trash = false): array
{
    $groups = [];
    foreach (kse_collect_event_rows($include_trash) as $row) {
        $key = kse_identity_key_for_row($row);
        if ($key === '') continue;
        $groups[$key][] = (int)$row['id'];
    }
    return array_filter($groups, static function ($ids) { return count($ids) > 1; });
}}
