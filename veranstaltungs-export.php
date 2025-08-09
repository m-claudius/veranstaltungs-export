<?php
/**
 * Plugin Name: Veranstaltungs-Export
 * Description: Crawlt Events der Gemeinde Seevetal und zeigt sie im Admin an (Zwei-Stufen: Suche -> Detail).
 * Version: 2.0.0
 * Author: ChatGPT
 * Text Domain: ve_export
 */

if (!defined('ABSPATH')) exit;

define('VE_EXPORT_VERSION', '2.0.0');
define('VE_EXPORT_PATH', plugin_dir_path(__FILE__));
define('VE_EXPORT_URL',  plugin_dir_url(__FILE__));

// ---- Includes ----
require_once VE_EXPORT_PATH . 'admin/menu.php';
require_once VE_EXPORT_PATH . 'crawler/SeevetalParser.php';

// ---- Assets nur auf unserer Admin-Seite laden ----
add_action('admin_enqueue_scripts', function($hook) {
    // Wenn der Menü-Slug 've_export' ist, dann lautet der Screen i.d.R. 'toplevel_page_ve_export'
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && $screen->id === 'toplevel_page_ve_export') {
        wp_enqueue_style('ve-admin',  VE_EXPORT_URL . 'assets/admin.css', [], VE_EXPORT_VERSION);
        wp_enqueue_script('ve-admin', VE_EXPORT_URL . 'assets/admin.js', ['jquery'], VE_EXPORT_VERSION, true);
    }
});

// Optional: Default-Option setzen bei Aktivierung
register_activation_hook(__FILE__, function() {
    if (get_option('ve_search_terms') === false) {
        add_option('ve_search_terms', 'kultur, musik');
    }
});
