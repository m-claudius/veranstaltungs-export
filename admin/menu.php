<?php
/**
 * Admin-Menü & Aktionen für den Parser "Musik in alten Heidekirchen"
 */

if (!defined('ABSPATH')) {
    exit;
}

// Sicherstellen, dass die Parser-Klasse verfügbar ist
if (!class_exists('MusikInAltenHeidekirchenParser')) {
    require_once KSE_PLUGIN_DIR . 'crawler/MusikInAltenHeidekirchenParser.php';
}

/**
 * Menüeintrag registrieren.
 * Standard: Unter "Werkzeuge" (Tools). Den Eltern-Slug kannst du via Filter anpassen.
 * Beispiel: add_filter('kse_admin_parent_slug', fn() => 'options-general.php'); // dann unter "Einstellungen"
 */
add_action('admin_menu', function () {
    $parent = apply_filters('kse_admin_parent_slug', 'tools.php'); // tools.php | options-general.php | edit.php?post_type=tribe_events | etc.

    add_submenu_page(
        $parent,
        'Musik in alten Heidekirchen – Parser',
        'Musik-in-Heidekirchen',
        'manage_options',
        'kse-musikheide-parser',
        'kse_render_musikheide_parser_admin'
    );
});

/**
 * Seite rendern
 */
function kse_render_musikheide_parser_admin()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('Du hast keine ausreichenden Rechte.', 'kse'));
    }

    $default_url = MusikInAltenHeidekirchenParser::LIST_URL;
    $list_url    = isset($_GET['list_url']) ? esc_url_raw(wp_unslash($_GET['list_url'])) : $default_url;
    $limit       = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 10;

    $didParse = isset($_GET['parsed']) && $_GET['parsed'] === '1';
    $events   = [];

    if ($didParse) {
        // Ergebnisse kurzzeitig aus Transient ziehen
        $events = get_transient('kse_musikheide_last_preview') ?: [];
        delete_transient('kse_musikheide_last_preview');
    }
    ?>
    <div class="wrap">
        <h1>Musik in alten Heidekirchen – Parser</h1>
        <p>Hole Termine von <code><?php echo esc_html(MusikInAltenHeidekirchenParser::LIST_URL); ?></code> und zeige eine Vorschau.</p>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1em;">
            <?php wp_nonce_field('kse_run_musikheide_parse'); ?>
            <input type="hidden" name="action" value="kse_run_musikheide_parse">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="list_url">Listen-URL</label></th>
                    <td>
                        <input type="url" id="list_url" name="list_url" class="regular-text" value="<?php echo esc_attr($list_url); ?>">
                        <p class="description">Standard: <?php echo esc_html($default_url); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="limit">Max. Anzahl</label></th>
                    <td>
                        <input type="number" id="limit" name="limit" min="1" max="100" value="<?php echo (int)$limit; ?>">
                    </td>
                </tr>
            </table>

            <?php submit_button('Vorschau laden'); ?>
        </form>

        <?php if ($didParse): ?>
            <h2 style="margin-top:2em;">Vorschau (<?php echo count($events); ?> gefunden)</h2>
            <?php if (empty($events)): ?>
                <p>Keine Events gefunden.</p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th>Titel</th>
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
                            <td>
                                <strong><?php echo esc_html($ev['post_title'] ?? ''); ?></strong>
                                <?php if (!empty($ev['description'])): ?>
                                    <div style="max-width:520px;max-height:7.5em;overflow:auto;margin-top:.5em;background:#fff;border:1px solid #ddd;padding:.5em;">
                                        <?php echo wp_kses_post($ev['description']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($ev['event_start'] ?? ''); ?></td>
                            <td><?php echo esc_html($ev['event_end'] ?? ''); ?></td>
                            <td><?php echo esc_html($ev['location'] ?? ''); ?></td>
                            <td>
                                <?php if (!empty($ev['image'])): ?>
                                    <img src="<?php echo esc_url($ev['image']); ?>" alt="" style="max-width:140px;height:auto;">
                                <?php endif; ?>
                            </td>
                            <td><a href="<?php echo esc_url($ev['source'] ?? '#'); ?>" target="_blank" rel="noopener noreferrer">öffnen</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Handler: Vorschau ausführen
 */
add_action('admin_post_kse_run_musikheide_parse', function () {
    if (!current_user_can('manage_options')) {
        wp_die(__('Du hast keine ausreichenden Rechte.', 'kse'));
    }
    check_admin_referer('kse_run_musikheide_parse');

    $list_url = isset($_POST['list_url']) ? esc_url_raw(wp_unslash($_POST['list_url'])) : MusikInAltenHeidekirchenParser::LIST_URL;
    $limit    = isset($_POST['limit']) ? max(1, (int) $_POST['limit']) : 10;

    $parser = new MusikInAltenHeidekirchenParser();
    $events = $parser->preview($limit, $list_url);

    // kurz puffern und zurück zur Seite
    set_transient('kse_musikheide_last_preview', $events, 60);

    $redirect = add_query_arg([
        'page'     => 'kse-musikheide-parser',
        'parsed'   => '1',
        'list_url' => rawurlencode($list_url),
        'limit'    => $limit,
    ], admin_url('tools.php')); // Muss zum Eltern-Slug passen

    // Wenn der Parent via Filter geändert wurde, korrigieren:
    $parent = apply_filters('kse_admin_parent_slug', 'tools.php');
    if ($parent !== 'tools.php') {
        // z.B. options-general.php
        $redirect = add_query_arg([], admin_url($parent));
        $redirect = add_query_arg([
            'page'     => 'kse-musikheide-parser',
            'parsed'   => '1',
            'list_url' => rawurlencode($list_url),
            'limit'    => $limit,
        ], $redirect);
    }

    wp_safe_redirect($redirect);
    exit;
});
