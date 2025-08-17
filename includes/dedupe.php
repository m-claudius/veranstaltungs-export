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

        // Duplikat in den Papierkorb
        wp_trash_post($dup);
        $trashed[] = $dup;
    }

    // final updaten
    wp_update_post(['ID'=>$primary,'post_content'=>$content]);
    if (!empty($terms)) wp_set_object_terms($primary, $terms, 'tribe_events_cat', false);

    return ['merged'=>true,'primary'=>$primary,'trashed'=>$trashed];
}}
