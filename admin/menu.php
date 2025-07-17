<?php
function ve_register_admin_menu() {
    add_menu_page(
        'Veranstaltungsquellen',
        'Veranstaltungsquellen',
        'manage_options',
        've_sources',
        've_render_admin_page'
    );
}
add_action('admin_menu', 've_register_admin_menu');

function ve_render_admin_page() {
    echo '<div class="wrap"><h1>Veranstaltungsquellen</h1>';
    echo '<form method="post">';
    echo '<textarea name="search_terms" rows="4" cols="60">' . esc_textarea($_POST['search_terms'] ?? 'kultur, musik') . '</textarea><br>';
    echo '<input type="submit" class="button button-primary" value="Speichern & Anzeigen">';
    echo '</form>';

    if (!empty($_POST['search_terms'])) {
        $terms = preg_split('/[,\s]+/', $_POST['search_terms']);
        foreach ($terms as $term) {
            $id = preg_replace('/[^a-z0-9]/i', '', $term);
            echo "<p><strong>" . esc_html($term) . "</strong> ";
            echo "<span class='ve-lupe' data-term='" . esc_attr($term) . "' style='cursor:pointer;color:#007cba;'>🔍</span></p>";
            echo "<div id='result-$id'></div>";
        }
    }
    echo '</div>';
}