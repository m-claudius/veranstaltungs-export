<?php
if (!defined('ABSPATH')) exit;

/** ======= Tabellen-Helfer ======= */
if (!function_exists('kse_stats_table_name')) {
function kse_stats_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'kse_stats';
}}

if (!function_exists('kse_stats_table_exists')) {
function kse_stats_table_exists() {
    global $wpdb;
    $table = kse_stats_table_name();
    $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    return strtolower($found ?? '') === strtolower($table);
}}

if (!function_exists('kse_stats_install')) {
function kse_stats_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table   = kse_stats_table_name();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        run_at DATETIME NOT NULL,
        source VARCHAR(40) NOT NULL,
        scanned INT UNSIGNED NOT NULL DEFAULT 0,
        created INT UNSIGNED NOT NULL DEFAULT 0,
        updated INT UNSIGNED NOT NULL DEFAULT 0,
        skipped INT UNSIGNED NOT NULL DEFAULT 0,
        blacklisted INT UNSIGNED NOT NULL DEFAULT 0,
        notes TEXT NULL,
        PRIMARY KEY  (id),
        KEY run_at (run_at),
        KEY source (source)
    ) $charset;";
    dbDelta($sql);
}}

if (!function_exists('kse_stats_maybe_install')) {
function kse_stats_maybe_install() {
    if (!kse_stats_table_exists()) kse_stats_install();
}}

/** ======= API ======= */
if (!function_exists('kse_stats_log')) {
function kse_stats_log($source, array $m, $notes = '') {
    global $wpdb;
    kse_stats_maybe_install();
    $table = kse_stats_table_name();
    $wpdb->insert($table, [
        'run_at'      => current_time('mysql'),
        'source'      => substr((string)$source, 0, 40),
        'scanned'     => (int)($m['scanned'] ?? 0),
        'created'     => (int)($m['created'] ?? 0),
        'updated'     => (int)($m['updated'] ?? 0),
        'skipped'     => (int)($m['skipped'] ?? 0),
        'blacklisted' => (int)($m['blacklisted'] ?? 0),
        'notes'       => $notes,
    ]);
}}

if (!function_exists('kse_stats_recent')) {
function kse_stats_recent($limit = 50) {
    global $wpdb;
    $limit = max(1, min((int)$limit, 500));
    if (!kse_stats_table_exists()) return [];
    $table = kse_stats_table_name();
    return $wpdb->get_results("SELECT * FROM $table ORDER BY run_at DESC LIMIT $limit", ARRAY_A);
}}

/** ======= Admin-Seite ======= */
if (!function_exists('kse_render_stats_admin')) {
function kse_render_stats_admin() {
    echo '<div class="wrap"><h1>Statistik</h1>';
    kse_stats_maybe_install();

    $rows = kse_stats_recent(100);
    if (!$rows) {
        echo '<p>Noch keine Einträge.</p></div>';
        return;
    }

    // Aggregation pro Tag & Quelle
    $agg = [];
    foreach ($rows as $r) {
        $day = substr($r['run_at'], 0, 10);
        $key = $day.'|'.$r['source'];
        if (!isset($agg[$key])) $agg[$key] = ['day'=>$day,'source'=>$r['source'],'created'=>0,'updated'=>0,'skipped'=>0];
        $agg[$key]['created'] += (int)$r['created'];
        $agg[$key]['updated'] += (int)$r['updated'];
        $agg[$key]['skipped'] += (int)$r['skipped'];
    }

    echo '<h2>Letzte Läufe</h2>
    <table class="widefat striped"><thead><tr>
      <th>Datum/Zeit</th><th>Quelle</th><th>Scanned</th><th>Created</th><th>Updated</th><th>Skipped</th><th>Blacklisted</th>
    </tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>'.esc_html($r['run_at']).'</td><td>'.esc_html($r['source']).'</td><td>'.
              (int)$r['scanned'].'</td><td>'.(int)$r['created'].'</td><td>'.
              (int)$r['updated'].'</td><td>'.(int)$r['skipped'].'</td><td>'.
              (int)$r['blacklisted'].'</td></tr>';
    }
    echo '</tbody></table>';

    // Mini-Chart
    echo '<h2 style="margin-top:1rem">Aggregiert (Created/Updated/Skipped)</h2>
    <div id="kse-chart" style="width:100%;height:360px"></div>
    <script src="https://www.gstatic.com/charts/loader.js"></script>
    <script>
      google.charts.load("current", {"packages":["corechart"]});
      google.charts.setOnLoadCallback(drawKSEChart);
      function drawKSEChart() {
        var data = new google.visualization.DataTable();
        data.addColumn("string", "Tag|Quelle");
        data.addColumn("number", "Created");
        data.addColumn("number", "Updated");
        data.addColumn("number", "Skipped");
        data.addRows([';

    $rowsjs = [];
    foreach ($agg as $k=>$v) {
        $label = $v['day'].' | '.$v['source'];
        $rowsjs[] = '["'.esc_js($label).'", '.(int)$v['created'].', '.(int)$v['updated'].', '.(int)$v['skipped'].']';
    }
    echo implode(',', $rowsjs);

    echo ']);
        var opt = {legend:{position:"bottom"}, chartArea:{width:"85%",height:"70%"}};
        new google.visualization.ColumnChart(document.getElementById("kse-chart")).draw(data, opt);
      }
      window.addEventListener("resize", drawKSEChart);
    </script>';

    echo '</div>';
}}
