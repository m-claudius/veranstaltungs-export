<?php
/**
 * Admin-Seite "Dubletten" - Diagnose und Bereinigung
 *
 * Beantwortet zwei Fragen:
 *  1. Warum hat die Existenzprüfung gegriffen bzw. nicht gegriffen?
 *     (Wie viele Events tragen überhaupt eine Quell-Kennung?)
 *  2. Welche Events sind mehrfach vorhanden - und weg damit.
 *
 * Alle Abfragen laufen direkt über $wpdb, damit The Events Calendar die
 * Diagnose nicht mit seinen Query-Filtern verfälscht.
 */
if (!defined('ABSPATH')) exit;

/* =========================================================
 * Kennzahlen
 * =======================================================*/

if (!function_exists('kse_dub_stats')) {
function kse_dub_stats(): array
{
    global $wpdb;

    $count = static function (string $where) use ($wpdb): int {
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
               FROM {$wpdb->posts} p
               LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
              WHERE p.post_type = 'tribe_events'
                AND p.post_status IN ('publish','draft','pending','private','future')
                AND {$where}"
        );
    };

    return [
        'events_total' => $count("1=1"),
        'mit_uid'      => $count("m.meta_key = '_kse_source_uid' AND m.meta_value <> ''"),
        'mit_url'      => $count("m.meta_key = '_kse_source_url' AND m.meta_value <> ''"),
        'mit_slug'     => $count("m.meta_key = '_kse_source_slug' AND m.meta_value <> ''"),
        'im_papierkorb'=> (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
              WHERE post_type = 'tribe_events' AND post_status = 'trash'"
        ),
    ];
}}

if (!function_exists('kse_dub_zero_cost_ids')) {
/**
 * Importierte Events, deren Preisfeld eine reine Null enthält.
 *
 * The Events Calendar zeigt dafür "Kostenlos" im Kopf der Event-Seite an,
 * obwohl der Preis aus der Quelle gar nicht bekannt ist. Bewusst nur Events
 * mit Quell-Kennung, damit von Hand gepflegte Termine unberührt bleiben.
 *
 * @return int[]
 */
function kse_dub_zero_cost_ids(): array
{
    global $wpdb;

    $sql = "SELECT DISTINCT p.ID
              FROM {$wpdb->posts} p
              INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_EventCost'
              INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID
                     AND s.meta_key IN ('_kse_source_url','_kse_source_uid')
                     AND s.meta_value <> ''
             WHERE p.post_type = 'tribe_events'
               AND p.post_status IN ('publish','draft','pending','private','future')
               AND TRIM(c.meta_value) REGEXP '^0([.,]0+)?$'";

    return array_map('intval', (array) $wpdb->get_col($sql));
}}

/* =========================================================
 * Seite
 * =======================================================*/

if (!function_exists('kse_render_dubletten')) {
function kse_render_dubletten()
{
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');

    $include_trash = isset($_GET['mit_papierkorb']);
    $stats  = kse_dub_stats();
    $groups = function_exists('kse_find_duplicate_groups_by_identity')
        ? kse_find_duplicate_groups_by_identity($include_trash)
        : [];

    echo '<div class="wrap"><h1>Dubletten</h1>';

    // ── Rückmeldungen der Aktionen ──
    if (isset($_GET['kse-uid-done'])) {
        printf(
            '<div class="notice notice-success is-dismissible"><p><strong>Kennungen nachgetragen.</strong> Geprüft: %d &nbsp;|&nbsp; Ergänzt: %d</p></div>',
            (int)($_GET['geprueft'] ?? 0),
            (int)($_GET['ergaenzt'] ?? 0)
        );
    }
    if (isset($_GET['kse-cost-done'])) {
        printf(
            '<div class="notice notice-success is-dismissible"><p><strong>Preisfelder geleert.</strong> Betroffene Veranstaltungen: %d</p></div>',
            (int)($_GET['geleert'] ?? 0)
        );
    }
    if (isset($_GET['kse-merge-done'])) {
        printf(
            '<div class="notice notice-success is-dismissible"><p><strong>Dubletten zusammengeführt.</strong> Gruppen: %d &nbsp;|&nbsp; in den Papierkorb verschoben: %d</p></div>',
            (int)($_GET['gruppen'] ?? 0),
            (int)($_GET['entfernt'] ?? 0)
        );
    }

    // ── Kennzahlen ──
    echo '<h2>Bestand</h2>';
    echo '<table class="widefat striped" style="max-width:640px"><tbody>';
    printf('<tr><td>Veranstaltungen gesamt</td><td><strong>%d</strong></td></tr>', $stats['events_total']);
    printf('<tr><td>davon mit stabiler Kennung <code>_kse_source_uid</code></td><td><strong>%d</strong></td></tr>', $stats['mit_uid']);
    printf('<tr><td>davon mit Quell-URL <code>_kse_source_url</code></td><td><strong>%d</strong></td></tr>', $stats['mit_url']);
    printf('<tr><td>davon mit Quellen-Kürzel <code>_kse_source_slug</code></td><td><strong>%d</strong></td></tr>', $stats['mit_slug']);
    printf('<tr><td>im Papierkorb</td><td><strong>%d</strong></td></tr>', $stats['im_papierkorb']);
    $zero_cost = count(kse_dub_zero_cost_ids());
    printf('<tr><td>importierte Events mit Preis 0 (Anzeige „Kostenlos“)</td><td><strong>%d</strong></td></tr>', $zero_cost);
    echo '</tbody></table>';

    if ($stats['mit_url'] > $stats['mit_uid']) {
        echo '<div class="notice notice-warning inline"><p>'
            . 'Es gibt Veranstaltungen mit Quell-URL, aber ohne stabile Kennung. '
            . 'Erst „Kennungen nachtragen“ ausführen, dann zusammenführen.'
            . '</p></div>';
    }

    // ── Aktionen ──
    $uid_url = wp_nonce_url(admin_url('admin-post.php?action=kse_backfill_uids'), 'kse_dubletten');
    $mrg_url = wp_nonce_url(admin_url('admin-post.php?action=kse_merge_duplicates'), 'kse_dubletten');
    $trash_toggle = $include_trash
        ? remove_query_arg('mit_papierkorb')
        : add_query_arg(['mit_papierkorb' => 1]);

    echo '<h2>Aktionen</h2><p>';
    echo '<a class="button" href="' . esc_url($uid_url) . '">Kennungen nachtragen</a> ';
    echo '<a class="button button-primary" href="' . esc_url($mrg_url) . '" '
        . 'onclick="return confirm(\'Alle gefundenen Dubletten zusammenführen? Die überzähligen Einträge wandern in den Papierkorb.\')">'
        . 'Alle Dubletten zusammenführen</a> ';
    echo '<a class="button" href="' . esc_url($trash_toggle) . '">'
        . ($include_trash ? 'Papierkorb ausblenden' : 'Papierkorb einbeziehen') . '</a>';
    echo '</p>';

    if ($zero_cost > 0) {
        $cost_url = wp_nonce_url(admin_url('admin-post.php?action=kse_clear_zero_cost'), 'kse_dubletten');
        echo '<p>';
        echo '<a class="button" href="' . esc_url($cost_url) . '" '
            . 'onclick="return confirm(\'Bei ' . (int)$zero_cost . ' importierten Veranstaltungen das Preisfeld leeren? Damit verschwindet die Angabe „Kostenlos“.\')">'
            . 'Preisangabe „Kostenlos“ entfernen (' . (int)$zero_cost . ')</a>';
        echo ' <span class="description">Nur bei Events mit Quell-Kennung – von Hand gepflegte Preise bleiben stehen.</span>';
        echo '</p>';
    }

    // ── Gruppen ──
    printf('<h2>Dubletten-Gruppen: %d</h2>', count($groups));

    if (!$groups) {
        echo '<p>Keine Mehrfach-Einträge gefunden.</p></div>';
        return;
    }

    if (function_exists('kse_table_styles_inline')) kse_table_styles_inline();

    echo '<table class="kse-table"><thead><tr>'
        . '<th>Kennung</th><th>Anzahl</th><th>Einträge</th>'
        . '</tr></thead><tbody>';

    foreach ($groups as $key => $ids) {
        echo '<tr>';
        echo '<td style="font-size:12px;word-break:break-all">' . esc_html($key) . '</td>';
        echo '<td><strong>' . count($ids) . '</strong></td>';
        echo '<td><ul style="margin:0">';
        foreach ($ids as $pid) {
            $start  = get_post_meta($pid, '_EventStartDate', true);
            $status = get_post_status($pid);
            printf(
                '<li>#%d <a href="%s">%s</a> &nbsp;<small>%s%s</small></li>',
                (int)$pid,
                esc_url(get_edit_post_link($pid) ?: '#'),
                esc_html(get_the_title($pid)),
                esc_html($start ?: 'ohne Startdatum'),
                $status !== 'publish' ? ' &middot; ' . esc_html($status) : ''
            );
        }
        echo '</ul></td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}}

/* =========================================================
 * Aktion: Kennungen nachtragen
 * =======================================================*/

if (!function_exists('kse_admin_backfill_uids')) {
add_action('admin_post_kse_backfill_uids', 'kse_admin_backfill_uids');
function kse_admin_backfill_uids()
{
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
    check_admin_referer('kse_dubletten');

    $geprueft = $ergaenzt = 0;
    if (!function_exists('kse_collect_event_rows') || !function_exists('kse_ei_source_uid')) {
        wp_safe_redirect(admin_url('admin.php?page=kse-dubletten'));
        exit;
    }
    foreach (kse_collect_event_rows(true) as $row) {
        if ($row['src'] === '') continue;
        $geprueft++;
        $uid = kse_ei_source_uid($row['src'], $row['slug']);
        if ($uid !== '' && $row['uid'] !== $uid) {
            update_post_meta($row['id'], '_kse_source_uid', $uid);
            $ergaenzt++;
        }
    }

    wp_safe_redirect(add_query_arg(
        ['kse-uid-done' => 1, 'geprueft' => $geprueft, 'ergaenzt' => $ergaenzt],
        admin_url('admin.php?page=kse-dubletten')
    ));
    exit;
}}

/* =========================================================
 * Aktion: Preisangabe "Kostenlos" entfernen
 * =======================================================*/

if (!function_exists('kse_admin_clear_zero_cost')) {
add_action('admin_post_kse_clear_zero_cost', 'kse_admin_clear_zero_cost');
function kse_admin_clear_zero_cost()
{
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
    check_admin_referer('kse_dubletten');

    $geleert = 0;
    if (function_exists('kse_dub_zero_cost_ids')) {
        foreach (kse_dub_zero_cost_ids() as $pid) {
            delete_post_meta($pid, '_EventCost');
            clean_post_cache($pid);
            $geleert++;
        }
    }

    wp_safe_redirect(add_query_arg(
        ['kse-cost-done' => 1, 'geleert' => $geleert],
        admin_url('admin.php?page=kse-dubletten')
    ));
    exit;
}}

/* =========================================================
 * Aktion: Dubletten zusammenführen
 * =======================================================*/

if (!function_exists('kse_admin_merge_duplicates')) {
add_action('admin_post_kse_merge_duplicates', 'kse_admin_merge_duplicates');
function kse_admin_merge_duplicates()
{
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
    check_admin_referer('kse_dubletten');

    $gruppen = $entfernt = 0;
    if (function_exists('kse_find_duplicate_groups_by_identity') && function_exists('kse_merge_event_group')) {
        foreach (kse_find_duplicate_groups_by_identity(false) as $ids) {
            $res = kse_merge_event_group($ids);
            if (!empty($res['merged'])) {
                $gruppen++;
                $entfernt += count($res['trashed']);
            }
        }
    }

    wp_safe_redirect(add_query_arg(
        ['kse-merge-done' => 1, 'gruppen' => $gruppen, 'entfernt' => $entfernt],
        admin_url('admin.php?page=kse-dubletten')
    ));
    exit;
}}
