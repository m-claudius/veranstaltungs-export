<?php
/**
 * Admin-Menü & UI für den Parser "Musik in alten Heidekirchen"
 */

if (!defined('ABSPATH')) { exit; }

if (!function_exists('kse_musikheide_register_menu')) {
    add_action('admin_menu', 'kse_musikheide_register_menu');
    function kse_musikheide_register_menu() {
        // Unter "Werkzeuge" einhängen
        add_submenu_page(
            'tools.php',
            'Musik in alten Heidekirchen – Parser',
            'Musik in alten Heidekirchen',
            'manage_options',
            'kse-musikheide-parser',
            'kse_render_musikheide_parser_admin'
        );
    }
}

if (!function_exists('kse_render_musikheide_parser_admin')) {
function kse_render_musikheide_parser_admin() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'kse'));
    }

    // Defaults aus Parser-Konstanten
    $default_url = defined('MusikInAltenHeidekirchenParser::LIST_URL') ? MusikInAltenHeidekirchenParser::LIST_URL : 'https://musik-in-alten-heidekirchen.wir-e.de/termine';
    $default_max = defined('MusikInAltenHeidekirchenParser::DEFAULT_MAX') ? MusikInAltenHeidekirchenParser::DEFAULT_MAX : 10;

    $list_url = isset($_POST['kse_list_url']) ? esc_url_raw($_POST['kse_list_url']) : $default_url;
    $max      = isset($_POST['kse_max']) ? intval($_POST['kse_max']) : $default_max;

    $events = [];
    $error  = '';

    if (isset($_POST['kse_action']) && $_POST['kse_action'] === 'preview' && check_admin_referer('kse_musikheide_preview')) {
        if (!class_exists('MusikInAltenHeidekirchenParser')) {
            $error = 'Parser-Klasse nicht gefunden.';
        } else {
            $parser = new MusikInAltenHeidekirchenParser();
            $events = $parser->crawl($list_url, $max);
        }
    }
    ?>
    <div class="wrap">
        <h1>Musik in alten Heidekirchen – Parser</h1>
        <p>Hole Termine von <code><?php echo esc_html($default_url); ?></code> und zeige eine Vorschau.</p>

        <form method="post">
            <?php wp_nonce_field('kse_musikheide_preview'); ?>
            <input type="hidden" name="kse_action" value="preview" />

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="kse_list_url">Listen-URL</label></th>
                        <td><input type="url" id="kse_list_url" name="kse_list_url" class="regular-text code" value="<?php echo esc_attr($list_url); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="kse_max">Max. Anzahl</label></th>
                        <td><input type="number" min="1" max="100" id="kse_max" name="kse_max" value="<?php echo esc_attr($max); ?>" /></td>
                    </tr>
                </tbody>
            </table>

            <p><button type="submit" class="button button-primary">Vorschau laden</button></p>
        </form>

        <?php if ($error): ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <?php if (!empty($events)): ?>
            <h2 class="title">Vorschau (<?php echo count($events); ?> gefunden)</h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:30%">Titel</th>
                        <th>Start</th>
                        <th>Ende</th>
                        <th>Ort</th>
                        <th>Bild</th>
                        <th>Quelle</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($events as $ev): ?>
                    <tr>
                        <td><?php echo esc_html($ev['title']); ?></td>
                        <td><?php echo esc_html($ev['start']); ?></td>
                        <td><?php echo esc_html($ev['end']); ?></td>
                        <td><?php echo esc_html($ev['location']); ?></td>
                        <td>
                            <?php if (!empty($ev['image'])): ?>
                                <img src="<?php echo esc_url($ev['image']); ?>" alt="" style="width:120px;height:auto"/>
                            <?php endif; ?>
                        </td>
                        <td><a href="<?php echo esc_url($ev['source']); ?>" target="_blank" rel="noopener">öffnen</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (isset($_POST['kse_action'])): ?>
            <p><em>Keine Events gefunden.</em></p>
        <?php endif; ?>
    </div>
    <?php
}}
