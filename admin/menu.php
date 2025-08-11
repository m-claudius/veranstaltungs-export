<?php
if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . '../crawler/SeevetalParser.php';

class VE_Admin_Page {
    private $parser;

    public function __construct() {
        $this->parser = new SeevetalParser();
    }

    public function render() {
        echo '<div class="wrap"><h1>Event Export – Seevetal</h1>';

        // Einzel-Detailansicht (Admin)
        if (isset($_GET['ve_action'], $_GET['url']) && $_GET['ve_action'] === 'details' && wp_verify_nonce($_GET['_wpnonce'] ?? '', 've_details')) {
            $url = esc_url_raw(rawurldecode($_GET['url']));
            $data = $this->parser->get_cached_detail($url);
            echo '<p><a href="' . esc_url(remove_query_arg(['ve_action','url','_wpnonce'])) . '">&larr; Zurück</a></p>';
            if (empty($data)) {
                echo '<div class="notice notice-error"><p>Detailseite konnte nicht geparst werden.</p></div>';
            } else {
                echo '<h2>Details</h2>';
                echo '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener">Externe Detailseite öffnen</a></p>';
                echo '<table class="widefat striped" style="max-width:900px">';
                foreach ($data as $k => $v) {
                    echo '<tr><th style="width:180px">' . esc_html($k) . '</th><td>';
                    if (is_array($v)) {
                        echo '<pre>' . esc_html(wp_json_encode($v, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) . '</pre>';
                    } else {
                        if ($k === 'image' && $v) {
                            echo '<img src="' . esc_url($v) . '" alt="" style="max-width:320px;display:block;margin-bottom:6px">';
                        }
                        echo nl2br(esc_html((string)$v));
                    }
                    echo '</td></tr>';
                }
                echo '</table>';
            }
            echo '</div>';
            return;
        }

        // Suchformular
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('ve_run')) {
            $termsCsv = sanitize_text_field($_POST['ve_terms'] ?? '');
            $terms = preg_split('/[,\s;]+/u', $termsCsv, -1, PREG_SPLIT_NO_EMPTY);

            if (empty($terms)) {
                echo '<div class="notice notice-warning"><p>Bitte mind. einen Suchbegriff angeben.</p></div>';
            } else {
                foreach ($terms as $term) {
                    $dbg = $this->parser->debug_collect_detail_links($term);
                    echo '<h2>Begriff: ' . esc_html($term) . '</h2>';
                    echo '<p><strong>Such-URL:</strong> <a target="_blank" rel="noopener" href="' . esc_url($dbg['url']) . '">' . esc_html($dbg['url']) . '</a></p>';
                    echo '<p><strong>Antwortgröße:</strong> ' . number_format_i18n($dbg['bytes']) . ' Bytes; <strong>Gefundene Detail-Links:</strong> ' . count($dbg['links']) . '</p>';

                    if ($dbg['links']) {
                        echo '<ol style="margin-left:1.4em">';
                        foreach ($dbg['links'] as $L) {
                            $admin = wp_nonce_url(
                                add_query_arg([
                                    'page'      => 've_export',
                                    've_action' => 'details',
                                    'url'       => rawurlencode($L),
                                ], admin_url('admin.php')),
                                've_details'
                            );
                            echo '<li><a href="' . esc_url($L) . '" target="_blank" rel="noopener">' . esc_html($L) . '</a> &nbsp;—&nbsp; <a href="' . esc_url($admin) . '">Details (Admin)</a></li>';
                        }
                        echo '</ol>';
                    } else {
                        echo '<p><em>Keine Links gefunden. Nutze die Such-URL oben zum Gegencheck (Seite lädt? Inhalte sichtbar?).</em></p>';
                    }
                    echo '<hr>';
                }
            }
        }

        echo '<form method="post" style="max-width:700px">';
        wp_nonce_field('ve_run');
        echo '<p><label for="ve_terms"><strong>Suchbegriffe</strong> (Komma/Leerzeichen getrennt):</label><br>';
        echo '<input type="text" class="regular-text" id="ve_terms" name="ve_terms" value="Musik Kultur Bildung Führungen Kunst Literatur Schauspiel Lesungen Konzert" style="width:100%"></p>';
        echo '<p><button type="submit" class="button button-primary">Suchen &amp; Links anzeigen</button></p>';
        echo '</form>';

        echo '</div>';
    }
}

// Wird in der Hauptdatei beim Menüaufruf instanziert:
// (new VE_Admin_Page())->render();
