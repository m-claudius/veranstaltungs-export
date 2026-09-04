<?php
if (!defined('ABSPATH')) exit;

/**
 * TEC Import-Utilities (WP + The Events Calendar Pro 7.7.0)
 *
 * Public API:
 *   kse_tec_upsert_event(array $payload, array $opts = []) : array
 *
 * $payload: [
 *   'title'       => string,
 *   'description' => string (HTML erlaubt),
 *   'start'       => 'Y-m-d H:i:s' (lokale WP-Zeit) oder beliebig parsebar,
 *   'end'         => 'Y-m-d H:i:s' (optional, s.o.),
 *   'location'    => string (optional),
 *   'image'       => string URL (optional),
 *   'source_url'  => string URL (optional, für Idempotenz & Quelle),
 *   'cost'        => string (optional, z.B. "12" oder "Eintritt frei";
 *                    leer = kein Preis bekannt, dann wird nichts behauptet),
 * ]
 *
 * $opts: [
 *   'default_duration_minutes' => int (Standard 120),
 *   'source_slug'              => string (z.B. 'empore'),
 *   'source_category'          => string (z.B. 'Empore Buchholz'), Alias: 'category'
 * ]
 *
 * Rückgabe:
 *   [
 *     'action'     => 'created' | 'updated'
 *                   | 'skipped_guard'              (fremde Quelle / _kse_protect)
 *                   | 'skipped_trashed'            (Treffer liegt im Papierkorb)
 *                   | 'skipped_duplicate_in_run'   (UID in diesem Lauf schon verarbeitet)
 *                   | 'skipped_no_date'            (kein verwertbares Startdatum)
 *                   | 'error',
 *     'post_id'    => int,
 *     'permalink'  => string,
 *     'matched_by' => string  (wie der Bestandstreffer gefunden wurde),
 *     'duplicates' => int[]   (weitere Posts derselben Identität),
 *     'reason'     => string  (nur bei skipped_... und error)
 *   ]
 *
 * Idempotenz läuft über _kse_source_uid (siehe includes/event-identity.php),
 * nicht mehr über die Detail-URL allein.
 */


/* ============================ Helpers ============================ */

/**
 * Normiert Datum/Zeit in lokale WP-Zeit (Y-m-d H:i:s).
 *
 * Achtung, alter Fehler: strtotime() + date_i18n() haben den GMT-Offset ein
 * zweites Mal addiert - aus 19:30 wurde 21:30, und über den Sommerzeit-Wechsel
 * hinweg verschob sich derselbe Termin um eine Stunde. Deshalb hier durchgängig
 * DateTime mit wp_timezone(): Strings ohne Zeitzonen-Angabe gelten als WP-Zeit,
 * Strings mit Offset (ISO 8601 aus JSON-LD) werden korrekt umgerechnet.
 *
 * Liefert '' wenn nichts Verwertbares drinsteht - bewusst kein "jetzt"-Fallback,
 * sonst landen undatierte Treffer als Event zur Importzeit im Kalender und
 * erzeugen bei jedem Lauf einen neuen Eintrag.
 */
if (!function_exists('kse_normalize_datetime_local')) {
function kse_normalize_datetime_local($in) {
    $in = trim((string)$in);
    if ($in === '') return '';

    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Berlin');
    try {
        $dt = new DateTime($in, $tz);
    } catch (\Throwable $e) {
        return '';
    }
    $dt->setTimezone($tz);

    $out = $dt->format('Y-m-d H:i:s');
    // "0000-00-00 ..." und ähnliche Platzhalter der Quellen aussortieren
    if (strpos($out, '0000-') === 0 || strpos($out, '-0001-') !== false) return '';
    return $out;
}}

/**
 * Post per exaktem Meta finden.
 *
 * Direkt per SQL statt WP_Query: The Events Calendar filtert jede WP_Query auf
 * 'tribe_events' (Custom Tables, Datumsfilter, Occurrence-IDs), und
 * post_status => 'any' schließt den Papierkorb aus. Beides hat dazu geführt,
 * dass bereits importierte Events nicht gefunden und neu angelegt wurden.
 */
if (!function_exists('kse_find_event_by_meta')) {
function kse_find_event_by_meta($key, $value) {
    if (!function_exists('kse_ei_ids_by_meta')) return 0;
    $ids = kse_ei_ids_by_meta((string)$key, (string)$value);
    return $ids ? (int)$ids[0] : 0;
}}

/** Post per (Title + Start) finden - ebenfalls per SQL, inkl. Papierkorb */
if (!function_exists('kse_find_event_by_title_and_start')) {
function kse_find_event_by_title_and_start($title, $start) {
    if (!function_exists('kse_ei_ids_by_title_start')) return 0;
    $title = trim((string)$title);
    $start = kse_normalize_datetime_local($start);
    if ($title === '' || $start === '') return 0;

    $ids = kse_ei_ids_by_title_start($title, $start, false);
    return $ids ? (int)$ids[0] : 0;
}}

/** "Darf überschrieben werden?" – einfacher Guard */
if (!function_exists('kse_can_overwrite_event')) {
function kse_can_overwrite_event($post_id, $source_slug = '') {
    if (get_post_meta($post_id, '_kse_protect', true)) {
        return ['blocked'=>true, 'reason'=>'protected_flag'];
    }
    $foreign = get_post_meta($post_id, '_kse_source_slug', true);
    if ($foreign && $source_slug && $foreign !== $source_slug) {
        return ['blocked'=>true, 'reason'=>'different_source'];
    }
    return ['blocked'=>false];
}}

/** Event-Kategorie holen/erstellen (tribe_events_cat) */
if (!function_exists('kse_get_or_create_event_cat')) {
function kse_get_or_create_event_cat($name) {
    $name = trim((string)$name);
    if ($name === '') return 0;
    $tax = 'tribe_events_cat';
    $t = term_exists($name, $tax);
    if (!$t || is_wp_error($t)) {
        $t = wp_insert_term($name, $tax);
        if (is_wp_error($t)) return 0;
    }
    return (int)($t['term_id'] ?? $t);
}}

/** Bild per URL anheften (featured image) – idempotent */
if (!function_exists('kse_attach_image_to_post')) {
function kse_attach_image_to_post($imageUrl, $post_id, $desc = '') {
    $imageUrl = esc_url_raw((string)$imageUrl);
    if (!$imageUrl || !$post_id) return 0;
    $prev = get_post_meta($post_id, '_kse_image_src', true);
    if ($prev && $prev === $imageUrl && has_post_thumbnail($post_id)) {
        return (int)get_post_thumbnail_id($post_id);
    }
    require_once ABSPATH.'wp-admin/includes/media.php';
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/image.php';
    $att_id = media_sideload_image($imageUrl, $post_id, $desc, 'id');
    if (is_wp_error($att_id)) return 0;
    set_post_thumbnail($post_id, $att_id);
    update_post_meta($post_id, '_kse_image_src', esc_url_raw($imageUrl));
    return (int)$att_id;
}}

/** TEC-Indizierung/Cache nachziehen (ohne Editor) */
if (!function_exists('kse_trigger_tec_index')) {
function kse_trigger_tec_index($post_id, $is_update) {
    $post = get_post($post_id);

    // Simuliere Editor-Save-Hooks
    do_action('save_post',              $post_id, $post, $is_update);
    do_action('save_post_tribe_events', $post_id, $post, $is_update);

    // TEC 6+: CT1 Index pflegen (wenn verfügbar)
    try {
        if (function_exists('tribe')) {
            if (class_exists('\\TEC\\Events\\Custom_Tables\\V1\\Manager')) {
                $mgr = tribe(\TEC\Events\Custom_Tables\V1\Manager::class);
                if (method_exists($mgr, 'rebuild_index_for_post')) {
                    $mgr->rebuild_index_for_post($post_id);
                }
            }
            if (class_exists('\\TEC\\Events\\Custom_Tables\\V1\\WP\\Query')) {
                $q = tribe(\TEC\Events\Custom_Tables\V1\WP\Query::class);
                if (method_exists($q, 'reset_cache')) $q->reset_cache();
            }
        }
    } catch (\Throwable $e) {
        // still fine
    }

    clean_post_cache($post_id);
    wp_cache_delete($post_id, 'posts');
    wp_cache_delete($post_id, 'post_meta');
}}


/* ============================ Main API ============================ */

/**
 * Upsert eines Events in TEC (ohne manuelles "Aktualisieren" im Editor).
 */
if (!function_exists('kse_tec_upsert_event')) {
function kse_tec_upsert_event(array $payload, array $opts = []) {

    $title  = trim((string)($payload['title'] ?? ''));
    $desc   = (string)($payload['description'] ?? '');
    $start  = kse_normalize_datetime_local($payload['start'] ?? '');
    $endIn  = (string)($payload['end'] ?? '');
    $loc    = trim((string)($payload['location'] ?? ''));
    $image  = trim((string)($payload['image'] ?? ''));
    $srcUrl = esc_url_raw((string)($payload['source_url'] ?? ''));

    $source_slug    = sanitize_key((string)($opts['source_slug'] ?? ''));
    // 'category' wird von den Cron-Runnern historisch als Schlüssel benutzt
    $source_catname = trim((string)($opts['source_category'] ?? ($opts['category'] ?? '')));
    $duration       = (int)($opts['default_duration_minutes'] ?? 120);

    if ($title === '') return ['action'=>'error', 'reason'=>'missing_title'];

    // Ohne brauchbares Startdatum wird nichts angelegt: früher wurde in diesem
    // Fall "jetzt" eingesetzt, und jeder Lauf erzeugte ein weiteres Event.
    if ($start === '') {
        return ['action'=>'skipped_no_date', 'post_id'=>0, 'reason'=>'unparsable_start'];
    }

    // Endzeit bestimmen
    $end = '';
    if ($endIn !== '') {
        $end = kse_normalize_datetime_local($endIn);
    }
    if ($end === '' || strtotime($end) <= strtotime($start)) {
        // Quellen liefern gelegentlich ein Ende vor dem Start (z. B. 00:00 Uhr)
        $endTs = strtotime($start) + max(1, $duration) * 60;
        $end   = date('Y-m-d H:i:s', $endTs);
    }

    // 1) Existierenden Event finden (Idempotenz)
    //    Reihenfolge: stabile UID -> Quell-URL -> Nolis-ID -> Titel+Start.
    //    Details und Begründung in includes/event-identity.php.
    $match = function_exists('kse_ei_find_event')
        ? kse_ei_find_event([
            'source_url'  => $srcUrl,
            'source_slug' => $source_slug,
            'title'       => $title,
            'start'       => $start,
          ])
        : ['post_id'=>0, 'uid'=>'', 'matched_by'=>'', 'duplicates'=>[], 'status'=>''];

    $post_id = (int)$match['post_id'];
    $uid     = (string)$match['uid'];

    // 1b) Schutz gegen Doppelverarbeitung innerhalb eines Laufs:
    //     Nolis liefert dieselbe Veranstaltung mehrfach (u. a. unter /buchen/),
    //     und zwei parallel laufende Cron-Durchläufe würden sonst beide anlegen.
    static $seen_uids = [];
    if ($uid !== '') {
        if (isset($seen_uids[$uid]) && !$post_id) {
            return [
                'action'    => 'skipped_duplicate_in_run',
                'post_id'   => (int)$seen_uids[$uid],
                'permalink' => get_permalink((int)$seen_uids[$uid]),
                'reason'    => 'uid_bereits_in_diesem_lauf_verarbeitet',
            ];
        }
    }

    // 1c) Treffer liegt im Papierkorb: nicht neu anlegen und nicht wiederbeleben.
    if ($post_id && $match['status'] === 'trash') {
        if ($uid !== '') $seen_uids[$uid] = $post_id;
        if (function_exists('kse_ei_backfill_uid')) kse_ei_backfill_uid($post_id, $srcUrl, $source_slug);
        return [
            'action'    => 'skipped_trashed',
            'post_id'   => $post_id,
            'permalink' => get_permalink($post_id),
            'reason'    => 'im_papierkorb',
        ];
    }

    // 2) Guard
    if ($post_id) {
        $guard = kse_can_overwrite_event($post_id, $source_slug);
        if (!empty($guard['blocked'])) {
            return [
                'action'    => 'skipped_guard',
                'post_id'   => $post_id,
                'permalink' => get_permalink($post_id),
                'reason'    => $guard['reason'] ?? 'guard',
            ];
        }
    }

    $is_update = false;

    // 3) INSERT über ORM (TEC 6+) – kein erzwungener Slug!
    if (!$post_id) {
        $created = false;

        // Bevorzugt: tec_events() (neuere Helper), sonst tribe_events()
        if (function_exists('tec_events')) {
            try {
                $created = tec_events()
                    ->set_args([
                        'title'       => ($title ?: '(ohne Titel)'),
                        'description' => $desc,
                        'status'      => 'publish',
                        'start_date'  => $start,
                        'end_date'    => $end,
                        'url'         => $srcUrl ?: null,
                    ])
                    ->create(); // WP_Post|false
            } catch (\Throwable $e) {
                $created = false;
            }
        }

        if (!$created && function_exists('tribe_events')) {
            try {
                $created = tribe_events()
                    ->set_args([
                        'title'       => ($title ?: '(ohne Titel)'),
                        'description' => $desc,
                        'status'      => 'publish',
                        'start_date'  => $start,
                        'end_date'    => $end,
                        'url'         => $srcUrl ?: null,
                    ])
                    ->create(); // WP_Post|false
            } catch (\Throwable $e) {
                $created = false;
            }
        }

        if ($created instanceof WP_Post) {
            $post_id = (int)$created->ID;
        } else {
            // Fallback: klassisch einfügen (für sehr alte Builds)
            $res = wp_insert_post([
                'post_type'    => 'tribe_events',
                'post_title'   => ($title ?: '(ohne Titel)'),
                'post_content' => $desc,
                'post_status'  => 'publish',
            ], true);
            if (is_wp_error($res)) return ['action'=>'error', 'reason'=>$res->get_error_message()];
            $post_id = (int)$res;
        }

    // 3b) UPDATE – Titel/Inhalt aktualisieren (Slug unangetastet)
    } else {
        $res = wp_update_post([
            'ID'           => $post_id,
            'post_title'   => ($title ?: '(ohne Titel)'),
            'post_content' => $desc,
            'post_status'  => 'publish',
        ], true);
        if (is_wp_error($res)) return ['action'=>'error', 'reason'=>$res->get_error_message()];
        $is_update = true;

        // Per TEC-API sicherstellen, dass Datumswerte & Index stimmen
        if (class_exists('Tribe__Events__API')) {
            try {
                Tribe__Events__API::update_event($post_id, [
                    'post_status' => 'publish',
                    'start_date'  => $start,
                    'end_date'    => $end,
                ]);
            } catch (\Throwable $e) {
                // Fallback: direkte Meta-Keys
                update_post_meta($post_id, '_EventStartDate',    $start);
                update_post_meta($post_id, '_EventEndDate',      $end);
                update_post_meta($post_id, '_EventStartDateUTC', get_gmt_from_date($start));
                update_post_meta($post_id, '_EventEndDateUTC',   get_gmt_from_date($end));
                update_post_meta($post_id, '_EventDuration',     max(1, strtotime($end) - strtotime($start)));
            }
        }
    }

    // 4) Source-Metas & Datums-Fallbacks (falls ORM/API nicht alle setzt)
    if ($srcUrl) {
        update_post_meta($post_id, '_kse_source_url', $srcUrl);
        update_post_meta($post_id, '_EventURL',       $srcUrl);
        // Stabile Identität schreiben - darüber läuft die Prüfung beim nächsten Lauf
        if (function_exists('kse_ei_backfill_uid')) {
            $uid = kse_ei_backfill_uid($post_id, $srcUrl, $source_slug);
        }
        if ($uid !== '') $seen_uids[$uid] = $post_id;
    }
    // Date-Metas (failsafe)
    if (!get_post_meta($post_id, '_EventStartDate', true)) {
        update_post_meta($post_id, '_EventStartDate',    $start);
        update_post_meta($post_id, '_EventEndDate',      $end);
        update_post_meta($post_id, '_EventStartDateUTC', get_gmt_from_date($start));
        update_post_meta($post_id, '_EventEndDateUTC',   get_gmt_from_date($end));
        update_post_meta($post_id, '_EventDuration',     max(1, strtotime($end) - strtotime($start)));
    }

    // 5) Source-Tag
    if ($source_slug !== '') {
        update_post_meta($post_id, '_kse_source_slug', $source_slug);
    }

    // 5b) Preis
    //
    // The Events Calendar schreibt "Kostenlos" in den Kopf der Event-Seite,
    // sobald _EventCost den Wert 0 enthält (leeres Feld = keine Anzeige).
    // Beim Anlegen über die TEC-ORM landet dort eine 0, obwohl wir den Preis
    // gar nicht kennen - die Seite behauptet dann etwas Falsches.
    //
    // Regel: gelieferten Preis übernehmen; eine reine Null entfernen;
    // alles von Hand Eingetragene unangetastet lassen.
    $cost     = trim((string)($payload['cost'] ?? ''));
    $cur_cost = (string) get_post_meta($post_id, '_EventCost', true);
    if ($cost !== '') {
        update_post_meta($post_id, '_EventCost', $cost);
    } elseif ($cur_cost !== '' && preg_match('~^0([.,]0+)?$~', $cur_cost)) {
        delete_post_meta($post_id, '_EventCost');
    }

    // 6) Ort idempotent anhängen (wie von dir gewünscht)
    if ($loc !== '') {
        update_post_meta($post_id, '_kse_location_raw', $loc);
        $content = get_post_field('post_content', $post_id);
        if ($content && mb_stripos($content, $loc) === false) {
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $content . "\n\n<p><strong>Ort:</strong> " . esc_html($loc) . "</p>",
            ]);
        }
    }

    // 7) Quelle idempotent anhängen
    if ($srcUrl) {
        $cur = get_post_field('post_content', $post_id);
        if ($cur && stripos($cur, 'Quelle: (C)') === false) {
            $append = "\n\n<p><em>Quelle: (C) <a href=\"" . esc_url($srcUrl) . "\" target=\"_blank\" rel=\"noopener\">" . esc_html($srcUrl) . "</a></em></p>";
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $cur . $append,
            ]);
        }
    }

    // 8) Kategorie setzen/anhängen
    if ($source_catname !== '') {
        $term_id = kse_get_or_create_event_cat($source_catname);
        if ($term_id) wp_set_object_terms($post_id, [$term_id], 'tribe_events_cat', true);
    }

    // 9) Bild anheften
    if ($image) {
        kse_attach_image_to_post($image, $post_id, $title);
    }

    // 10) TEC-Index/Caches aktualisieren -> Permalink sofort funktionsfähig (keine 404)
    kse_trigger_tec_index($post_id, $is_update);

    // 11) Auch die Alt-Dubletten derselben Identität bekommen die UID, damit die
    //     Bereinigung sie als eine Gruppe erkennt.
    if (!empty($match['duplicates']) && $uid !== '') {
        foreach ($match['duplicates'] as $dup_id) {
            update_post_meta((int)$dup_id, '_kse_source_uid', $uid);
        }
    }

    return [
        'action'     => $is_update ? 'updated' : 'created',
        'post_id'    => $post_id,
        'permalink'  => get_permalink($post_id),
        'matched_by' => (string)($match['matched_by'] ?? ''),
        'duplicates' => array_map('intval', (array)($match['duplicates'] ?? [])),
    ];
}}
