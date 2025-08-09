<?php
if (!defined('ABSPATH')) exit;

class Ve_Admin_Menu {
    private $option_name = 've_search_terms';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_ve_save_terms', [$this, 'save_terms']);
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Veranstaltungs-Export', 've_export'),
            __('Veranstaltungs-Export', 've_export'),
            'manage_options',           // Zugriff: Admins
            've_export',                // Slug (wichtig für Hook/Assets)
            [$this, 'render_admin_page'],
            'dashicons-calendar-alt'
        );
    }

    public function save_terms() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('ve_save_terms');

        $terms = isset($_POST['search_terms']) ? sanitize_text_field($_POST['search_terms']) : '';
        update_option($this->option_name, $terms);

        wp_redirect(admin_url('admin.php?page=ve_export'));
        exit;
    }

public function render_admin_page() {
    if (!current_user_can('manage_options')) return;

    // Router: Einzel-Details?
    if (isset($_GET['ve_action']) && $_GET['ve_action'] === 'details' && isset($_GET['url'])) {
        check_admin_referer('ve_details');
        $url = esc_url_raw( wp_unslash($_GET['url']) );
        $url = rawurldecode($url);

        $parser = new SeevetalParser();
        $data   = $parser->get_cached_detail($url);

        echo '<div class="wrap">';
        echo '<h1>Veranstaltungs-Details</h1>';
        echo '<p><a class="button" href="' . esc_url( admin_url('admin.php?page=ve_export') ) . '">← Zurück zur Übersicht</a></p>';
        echo $this->render_detail_view($data, $url);
        echo '</div>';
        return;
    }

    // Übersicht
    $terms = get_option($this->option_name, 'kultur, musik');
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Veranstaltungs-Export', 've_export') . '</h1>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ve-form">';
    wp_nonce_field('ve_save_terms');
    echo '<input type="hidden" name="action" value="ve_save_terms" />';
    echo '<p><label for="search_terms"><strong>Suchbegriffe</strong> (Komma oder Leerzeichen getrennt)</label></p>';
    echo '<textarea id="search_terms" name="search_terms" rows="3" class="large-text">' . esc_textarea($terms) . '</textarea>';
    echo '<p><button class="button button-primary">Speichern & Anzeigen</button></p>';
    echo '</form>';

    if (!empty($terms)) {
        $parser = new SeevetalParser();
        echo '<div class="ve-results">';
        echo $parser->display_results($terms);
        echo '</div>';
    }
    echo '</div>';
}

private function render_detail_view(array $e, $url) {
    if (empty($e['title'])) {
        return '<p>Keine Daten geparst.</p>';
    }
    ob_start();
    ?>
    <table class="widefat striped" style="max-width:1000px;">
        <tbody>
            <tr><th style="width:180px;">Titel</th><td><?php echo esc_html($e['title']); ?></td></tr>
            <tr><th>Datum/Zeit</th><td><?php echo esc_html($e['datetime']); ?></td></tr>
            <tr><th>Ort</th><td><?php echo esc_html($e['location']); ?></td></tr>
            <tr><th>Beschreibung</th><td><?php echo nl2br(esc_html($e['description'])); ?></td></tr>
            <tr><th>Kosten</th><td><?php echo esc_html($e['costs'] ?? ''); ?></td></tr>
            <tr><th>Alter</th><td><?php echo esc_html($e['age'] ?? ''); ?></td></tr>
            <tr><th>Anmeldung</th><td><?php echo esc_html($e['registration'] ?? ''); ?></td></tr>
            <tr><th>Veranstalter</th><td><?php echo esc_html($e['organizer'] ?? ''); ?></td></tr>
            <tr><th>Bild</th><td>
                <?php if (!empty($e['image'])): ?>
                    <img src="<?php echo esc_url($e['image']); ?>" style="max-width:300px;height:auto;" />
                <?php else: ?>
                    <em>Kein Bild</em>
                <?php endif; ?>
            </td></tr>
            <tr><th>Externe Seite</th><td><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Öffnen</a></td></tr>
        </tbody>
    </table>
    <p style="margin-top:12px;">
        <!-- Platzhalter für nächste Schritte -->
        <button class="button">Bild in Mediathek speichern</button>
        <button class="button">In The Events Calendar importieren</button>
    </p>
    <?php
    return ob_get_clean();
}

}

new Ve_Admin_Menu();
