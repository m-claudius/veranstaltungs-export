<?php
/*
Plugin Name: Veranstaltungs-Export
Plugin URI:  https://github.com/<dein-user>/veranstaltungs-export
Description: Crawler-Plugin Version 1.5.7 – Suchbegriffe mit klickbaren Lupen, automatische Detailseiten-Auswertung.
Version:     1.5.7
Author:      Kulturstiftung Seevetal
*/

defined('ABSPATH') or die();

// Assets laden
add_action('admin_enqueue_scripts', function() {
    wp_enqueue_script('ve-ajax', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery'], null, true);
    wp_localize_script('ve-ajax', 've_ajax', ['ajax_url' => admin_url('admin-ajax.php')]);
    wp_enqueue_style('ve-style', plugin_dir_url(__FILE__) . 'assets/admin.css');
});

// Admin-Menü
require_once plugin_dir_path(__FILE__) . 'admin/menu.php';
// Crawler-Parser
require_once plugin_dir_path(__FILE__) . 'crawler/SeevetalParser.php';

// AJAX-Handler für die Lupen-Suche
add_action('wp_ajax_ve_run_search', function() {
    if (!current_user_can('manage_options')) wp_die('Kein Zugriff');
    $term = sanitize_text_field($_POST['term'] ?? '');
    if (!$term) wp_die('Kein Suchbegriff');
    $parser = new SeevetalParser();
    $events = $parser->fetch($term);
    if (!$events) {
        echo '<p>Keine Veranstaltungen gefunden.</p>';
        wp_die();
    }
    foreach ($events as $e) {
        echo '<div class="event-block">';
        echo '<h3>' . esc_html($e['title']) . '</h3>';
        echo '<p>📅 ' . esc_html($e['date']) . ' | 📍 ' . esc_html($e['location']) . '</p>';
        echo '<p>' . esc_html(mb_strimwidth(strip_tags($e['description']), 0, 300, '...')) . '</p>';
        echo '<p>🔗 <a href="' . esc_url($e['url']) . '" target="_blank">Originalseite</a></p>';
        echo '</div><hr>';
    }
    wp_die();
});
