<?php
// admin/menu.php
if (!defined('ABSPATH')) { exit; }

/**
 * Menüeintrag unter "Werkzeuge" und Admin-Seite rendern
 */
add_action('admin_menu', function () {
    add_management_page(
        'Musik in alten Heidekirchen — Parser',
        'Musik in alten Heidekirchen',
        'manage_options',
        'kse-musikheide-parser',
        'kse_render_musikheide_parser_admin'
    );
});

/**
 * Admin-Seite: Liste-URL eingeben, Vorschau laden, Tabelle anzeigen
 */
function kse_render_musikheide_parser_admin() {
    // Parser sicher laden
    if (!class_exists('MusikInAltenHeidekirchenParser')) {
        require_once KSE_PLUGIN_DIR . 'crawler/MusikInAltenHeidekirchenParser.php';
    }

    $default_url = \MusikInAltenHeidekirchenParser::LIST_URL;
    $list_url = isset($_GET['list_url']) ? esc_url_raw($_GET['list_url']) : $default_url;
    $limit    = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 10;

    $events = [];
    if (isset($_GET['preview'])) {
        // WICHTIG: hier jetzt fetch() verwenden
        $events = \MusikInAltenHeidekirchenParser::fetch($list_url, $limit);
        if (!is_array($events)) { $events = []; }
    }
    ?>
    <div class="wrap">
        <h1>Musik in alten Heidekirchen – Parser</h1>

        <form method="get" style="margin:1rem 0;">
            <input type="hidden" name="page" value="kse-musikheide-parser" />
            <label for="list_url">Listen-URL</label>
            <input type="url" id="list_url" name="list_url" value="<?php echo esc_attr($list_url); ?>" size="80" />
            <label for="limit" style="margin-left:1rem;">Max. Anzahl</label>
            <input type="number" id="limit" name="limit" min="1" max="100" value="<?php echo esc_attr($limit); ?>" />
            <button class="button button-primary" type="submit" name="preview" value="1">Vorschau laden</button>
        </form>

        <h2 class="title">Vorschau (<?php echo count($events); ?> gefunden)</h2>

        <table class="widefat fixed striped">
            <thead>
            <tr>
                <th style="width:35%">Titel</th>
                <th>Start</th>
                <th>Ende</th>
                <th>Ort</th>
                <th style="width:140px">Bild</th>
                <th style="width:80px">Quelle</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$events): ?>
                <tr><td colspan="6">Keine Einträge.</td></tr>
            <?php else: foreach ($events as $ev): ?>
                <tr>
                    <td><?php echo esc_html($ev['title'] ?? ''); ?></td>
                    <td><?php echo esc_html($ev['start'] ?? ''); ?></td>
                    <td><?php echo esc_html($ev['end']   ?? ''); ?></td>
                    <td><?php echo esc_html($ev['location'] ?? ''); ?></td>
                    <td>
                        <?php if (!empty($ev['image'])): ?>
                            <img src="<?php echo esc_url($ev['image']); ?>" alt="" style="max-width:120px;height:auto;">
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($ev['source'])): ?>
                            <a href="<?php echo esc_url($ev['source']); ?>" target="_blank" rel="noopener">öffnen</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
