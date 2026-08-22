<?php
/**
 * Event-Identität & Dubletten-Erkennung
 *
 * Zentrale Frage: "Kenne ich diese Veranstaltung schon?"
 *
 * Warum eigene SQL-Abfragen statt WP_Query?
 *  - The Events Calendar hängt sich per pre_get_posts / Custom Tables in jede
 *    WP_Query auf 'tribe_events' ein (Datumsfilter, Occurrence-IDs, Caches).
 *    Für eine reine Existenzprüfung ist das unnötig und fehleranfällig.
 *  - WP_Query mit post_status 'any' schließt 'trash' aus. Ein Event im
 *    Papierkorb wurde dadurch nicht gefunden und beim nächsten Lauf neu angelegt.
 *
 * Identität wird über eine stabile UID geführt (_kse_source_uid), nicht über die
 * Detail-URL: Nolis (seevetal.de) ändert die URL, sobald sich der Titel ändert
 * ("VERLEGT: ..."), und liefert dieselbe Veranstaltung zusätzlich unter
 * /regional/veranstaltungen/buchen/... aus. Die numerische Nolis-ID bleibt gleich.
 *
 * Public API:
 *   kse_ei_source_uid(string $url, string $slug = ''): string
 *   kse_ei_normalize_url(string $url): string
 *   kse_ei_norm_title(string $title): string
 *   kse_ei_find_event(array $args): array
 *   kse_ei_ids_by_meta(string $key, string $value, string $compare = '='): array
 */
if (!defined('ABSPATH')) exit;

/* =========================================================
 * Normalisierung
 * =======================================================*/

if (!function_exists('kse_ei_normalize_url')) {
/**
 * Normalisiert eine Detail-URL für den Vergleich:
 * Schema/www weg, Nolis-Zusatzpfade (buchen/, drucken/ ...) weg,
 * Tracking-Parameter weg, kein abschließender Slash.
 */
function kse_ei_normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';

    $p = @wp_parse_url($url);
    if (!is_array($p) || empty($p['host'])) {
        return strtolower(rtrim($url, '/'));
    }

    $host = strtolower($p['host']);
    if (strpos($host, 'www.') === 0) $host = substr($host, 4);

    $path = $p['path'] ?? '/';
    // Nolis liefert dieselbe Veranstaltung unter Zusatzpfaden aus
    $path = preg_replace('~/(buchen|drucken|merken|merkzettel|weiterempfehlen)/~i', '/', $path);
    $path = rtrim((string)$path, '/');

    // Query behalten (andere Quellen identifizieren Events darüber),
    // aber Tracking-Parameter entfernen
    $query = '';
    if (!empty($p['query'])) {
        parse_str($p['query'], $qargs);
        foreach (array_keys($qargs) as $k) {
            if (preg_match('~^(utm_|fbclid|gclid|mc_cid|mc_eid|_ga)~i', (string)$k)) {
                unset($qargs[$k]);
            }
        }
        if ($qargs) {
            ksort($qargs);
            $query = '?' . http_build_query($qargs);
        }
    }

    return $host . $path . $query;
}}

if (!function_exists('kse_ei_source_uid')) {
/**
 * Stabile Kennung einer Veranstaltung an ihrer Quelle.
 *
 * Nolis-CMS (seevetal.de, Burg Seevetal): die numerische ID in der URL,
 * z. B. .../pool-party-...-910027108-20200.html -> "nolis:seevetal.de:910027108".
 * Sie überlebt Titel-/Slug-Änderungen und den /buchen/-Pfad.
 *
 * Alle anderen Quellen: Hash der normalisierten URL.
 */
function kse_ei_source_uid(string $url, string $slug = ''): string
{
    $url = trim($url);
    if ($url === '') return '';

    $norm = kse_ei_normalize_url($url);
    $host = strtok($norm, '/');

    // Nolis-Detailseite: <slug>-<eventid>-<viewid>.html
    if (preg_match('~-(\d{6,})-(\d{4,6})\.html$~i', $norm, $m)) {
        return 'nolis:' . $host . ':' . $m[1];
    }

    $prefix = $slug !== '' ? sanitize_key($slug) : 'url';
    return $prefix . ':' . md5($norm);
}}

if (!function_exists('kse_ei_nolis_id')) {
/** Liefert die reine Nolis-Event-ID aus einer URL - oder '' */
function kse_ei_nolis_id(string $url): string
{
    if (preg_match('~-(\d{6,})-(\d{4,6})\.html~i', $url, $m)) return $m[1];
    return '';
}}

if (!function_exists('kse_ei_norm_title')) {
/** Titel auf einen vergleichbaren Kern reduzieren */
function kse_ei_norm_title(string $t): string
{
    $t = wp_strip_all_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
    $t = mb_strtolower($t, 'UTF-8');

    // typografische Zeichen vereinheitlichen
    $t = str_replace(
        ['„', '“', '”', '‚', '‘', '’', '–', '—', "\xC2\xA0"],
        ['"', '"', '"', "'", "'", "'", '-', '-', ' '],
        $t
    );

    // Status-Präfixe der Quellen ignorieren ("VERLEGT: ...", "ABGESAGT - ...")
    $t = preg_replace('~^\s*(verlegt|abgesagt|ausverkauft|neuer termin|achtung)\s*[:\-]\s*~u', '', (string)$t);

    $t = preg_replace('~[^a-z0-9äöüß ]+~u', ' ', (string)$t);
    $t = preg_replace('~\s+~', ' ', (string)$t);

    return trim((string)$t);
}}

/* =========================================================
 * SQL-Lookups (bewusst ohne WP_Query)
 * =======================================================*/

if (!function_exists('kse_ei_statuses_sql')) {
/** Post-Status, die als "existiert bereits" zählen - inkl. trash, ohne auto-draft */
function kse_ei_statuses_sql(): string
{
    return "'publish','draft','pending','private','future','trash'";
}}

if (!function_exists('kse_ei_ids_by_meta')) {
/**
 * Alle tribe_events-IDs zu einem Meta-Wert. Bewusst inkl. Papierkorb.
 *
 * @param string $compare '=' oder 'LIKE' ($value wird dann roh übernommen,
 *                        der Aufrufer muss $wpdb->esc_like() selbst anwenden)
 * @return int[]
 */
function kse_ei_ids_by_meta(string $key, string $value, string $compare = '='): array
{
    global $wpdb;
    if ($key === '' || $value === '') return [];

    $op  = ($compare === 'LIKE') ? 'LIKE' : '=';
    $sql = $wpdb->prepare(
        "SELECT DISTINCT p.ID
           FROM {$wpdb->posts} p
           INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
          WHERE p.post_type = 'tribe_events'
            AND p.post_status IN (" . kse_ei_statuses_sql() . ")
            AND m.meta_key = %s
            AND m.meta_value {$op} %s
          ORDER BY p.ID ASC",
        $key,
        $value
    );

    return array_map('intval', (array)$wpdb->get_col($sql));
}}

if (!function_exists('kse_ei_ids_by_title_start')) {
/**
 * Kandidaten über das Startdatum holen und den Titel normalisiert vergleichen.
 *
 * @param string $start    'Y-m-d H:i:s'
 * @param bool   $day_only true = gleicher Tag genügt (Uhrzeit egal)
 * @return int[]
 */
function kse_ei_ids_by_title_start(string $title, string $start, bool $day_only = false): array
{
    global $wpdb;

    $needle = kse_ei_norm_title($title);
    if ($needle === '' || $start === '') return [];

    if ($day_only) {
        $like = $wpdb->esc_like(substr($start, 0, 10)) . '%';
        $sql  = $wpdb->prepare(
            "SELECT p.ID, p.post_title
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_EventStartDate'
              WHERE p.post_type = 'tribe_events'
                AND p.post_status IN (" . kse_ei_statuses_sql() . ")
                AND m.meta_value LIKE %s
              ORDER BY p.ID ASC",
            $like
        );
    } else {
        $sql = $wpdb->prepare(
            "SELECT p.ID, p.post_title
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_EventStartDate'
              WHERE p.post_type = 'tribe_events'
                AND p.post_status IN (" . kse_ei_statuses_sql() . ")
                AND m.meta_value = %s
              ORDER BY p.ID ASC",
            $start
        );
    }

    $rows = (array)$wpdb->get_results($sql);
    $out  = [];
    foreach ($rows as $r) {
        if (kse_ei_norm_title((string)$r->post_title) === $needle) {
            $out[] = (int)$r->ID;
        }
    }
    return $out;
}}

/* =========================================================
 * Haupt-Lookup
 * =======================================================*/

if (!function_exists('kse_ei_find_event')) {
/**
 * Sucht ein bereits importiertes Event.
 *
 * @param array $args [
 *   'source_url'  => string,
 *   'source_slug' => string,
 *   'title'       => string,
 *   'start'       => string 'Y-m-d H:i:s',
 * ]
 * @return array [
 *   'post_id'    => int    (0 = nicht gefunden)
 *   'uid'        => string
 *   'matched_by' => string ''|'uid'|'source_url'|'nolis_id'|'title_start'|'title_day'
 *   'duplicates' => int[]  weitere Treffer derselben Identität
 *   'status'     => string post_status des Treffers
 * ]
 */
function kse_ei_find_event(array $args): array
{
    global $wpdb;

    $url   = trim((string)($args['source_url'] ?? ''));
    $slug  = (string)($args['source_slug'] ?? '');
    $title = (string)($args['title'] ?? '');
    $start = (string)($args['start'] ?? '');

    $uid = $url !== '' ? kse_ei_source_uid($url, $slug) : '';

    $res = [
        'post_id'    => 0,
        'uid'        => $uid,
        'matched_by' => '',
        'duplicates' => [],
        'status'     => '',
    ];

    $hits = [];
    $by   = '';

    // 1) Stabile UID - der Normalfall ab Version 2.2.0
    if ($uid !== '') {
        $hits = kse_ei_ids_by_meta('_kse_source_uid', $uid);
        if ($hits) $by = 'uid';
    }

    // 2) Alt-Bestand: exakte Quell-URL
    if (!$hits && $url !== '') {
        $hits = kse_ei_ids_by_meta('_kse_source_url', $url);
        if (!$hits) $hits = kse_ei_ids_by_meta('_EventURL', $url);
        if ($hits) $by = 'source_url';
    }

    // 3) Alt-Bestand mit geänderter URL: über die Nolis-ID im URL-Meta
    if (!$hits && $url !== '') {
        $nolis = kse_ei_nolis_id($url);
        if ($nolis !== '') {
            $like = '%' . $wpdb->esc_like('-' . $nolis . '-') . '%';
            $hits = kse_ei_ids_by_meta('_kse_source_url', $like, 'LIKE');
            if (!$hits) $hits = kse_ei_ids_by_meta('_EventURL', $like, 'LIKE');
            if ($hits) $by = 'nolis_id';
        }
    }

    // 4) Ohne verwertbare URL-Spur: Titel + exaktes Startdatum
    if (!$hits && $start !== '') {
        $hits = kse_ei_ids_by_title_start($title, $start, false);
        if ($hits) $by = 'title_start';
    }

    // 5) Letzte Stufe: Titel + gleicher Tag - nur wenn eindeutig
    if (!$hits && $start !== '') {
        $day = kse_ei_ids_by_title_start($title, $start, true);
        if (count($day) === 1) {
            $hits = $day;
            $by   = 'title_day';
        }
    }

    if (!$hits) return $res;

    sort($hits);
    $res['post_id']    = (int)array_shift($hits);
    $res['duplicates'] = array_map('intval', $hits);
    $res['matched_by'] = $by;
    $res['status']     = (string)get_post_status($res['post_id']);

    return $res;
}}

if (!function_exists('kse_ei_backfill_uid')) {
/** Schreibt die UID an einen Post (idempotent) */
function kse_ei_backfill_uid(int $post_id, string $url, string $slug = ''): string
{
    if ($post_id <= 0 || $url === '') return '';
    $uid = kse_ei_source_uid($url, $slug);
    if ($uid === '') return '';
    if (get_post_meta($post_id, '_kse_source_uid', true) !== $uid) {
        update_post_meta($post_id, '_kse_source_uid', $uid);
    }
    return $uid;
}}
