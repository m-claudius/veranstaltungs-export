<?php
/**
 * Testharness für die reinen Logik-Funktionen (ohne WordPress).
 * Stubs nur für das, was beim Laden bzw. in den getesteten Funktionen genutzt wird.
 */
define('ABSPATH', '/tmp/');

function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function sanitize_key($k) { return preg_replace('~[^a-z0-9_\-]~', '', strtolower((string)$k)); }
function wp_strip_all_tags($s) { return strip_tags((string)$s); }
function wp_timezone() { return new DateTimeZone('Europe/Berlin'); }

/** Post-Status für kse_ei_order_by_status(); ID => Status, Vorgabe 'publish' */
$GLOBALS['kse_test_post_status'] = [];
function get_post_status($id) { return $GLOBALS['kse_test_post_status'][(int)$id] ?? 'publish'; }

require_once __DIR__ . '/../includes/event-identity.php';
require_once __DIR__ . '/../includes/tec_import.php';

$pass = 0; $fail = 0;
function check(string $label, $actual, $expected) {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; return; }
    $fail++;
    echo "FEHLER: $label\n   erwartet: " . var_export($expected, true) . "\n   bekommen: " . var_export($actual, true) . "\n";
}

/* ---------- UID: Nolis-ID überlebt Slug- und Pfadänderung ---------- */
$base   = 'https://www.seevetal.de/regional/veranstaltungen/pool-party-im-freibad-hittfeld-am-8-august-2025-910027108-20200.html';
$buchen = 'https://www.seevetal.de/regional/veranstaltungen/buchen/pool-party-im-freibad-hittfeld-am-8-august-2025-910027108-20200.html';
$umbe   = 'https://www.seevetal.de/regional/veranstaltungen/verlegt-pool-party-im-freibad-hittfeld-910027108-20200.html';
$anders = 'https://www.seevetal.de/regional/veranstaltungen/pool-party-im-freibad-hittfeld-910027109-20200.html';

check('UID Basis-URL',        kse_ei_source_uid($base),   'nolis:seevetal.de:910027108');
check('UID /buchen/-Variante', kse_ei_source_uid($buchen), kse_ei_source_uid($base));
check('UID nach Umbenennung',  kse_ei_source_uid($umbe),   kse_ei_source_uid($base));
check('andere Event-ID ist andere UID', kse_ei_source_uid($anders) !== kse_ei_source_uid($base), true);
check('http vs https egal',    kse_ei_source_uid(str_replace('https://www.', 'http://', $base)), kse_ei_source_uid($base));

/* ---------- UID: Fremdquellen über normalisierte URL ---------- */
$e1 = 'https://empore-buchholz.de/veranstaltung/konzert-xy/';
$e2 = 'https://empore-buchholz.de/veranstaltung/konzert-xy?utm_source=newsletter';
check('Fremdquelle: Slash und utm egal', kse_ei_source_uid($e2, 'empore'), kse_ei_source_uid($e1, 'empore'));
check('Fremdquelle: Präfix aus slug', str_starts_with(kse_ei_source_uid($e1, 'empore'), 'empore:'), true);
$e3 = 'https://empore-buchholz.de/veranstaltung/konzert-yz/';
check('Fremdquelle: andere Seite andere UID', kse_ei_source_uid($e3, 'empore') !== kse_ei_source_uid($e1, 'empore'), true);

/* ---------- Nolis-ID-Extraktion ---------- */
check('Nolis-ID', kse_ei_nolis_id($base), '910027108');
check('Nolis-ID leer bei Fremd-URL', kse_ei_nolis_id($e1), '');

/* ---------- Titel-Normalisierung ---------- */
check('Titel: Status-Präfix ignoriert',
    kse_ei_norm_title('VERLEGT: Die Rubettes feat. Bill Hurd'),
    kse_ei_norm_title('Die Rubettes feat. Bill Hurd'));
check('Titel: typografische Anführungszeichen',
    kse_ei_norm_title('„Aber bitte mit Sahne“'),
    kse_ei_norm_title('"Aber bitte mit Sahne"'));
check('Titel: Umlaute bleiben erhalten',
    kse_ei_norm_title('Musikalische Führung'), 'musikalische führung');
check('Titel: verschiedene Titel bleiben verschieden',
    kse_ei_norm_title('Rudelsingen 8') === kse_ei_norm_title('Rudelsingen 9'), false);

/* ---------- Datum: kein doppelter Zeitzonen-Versatz mehr ---------- */
check('Datum: lokale Zeit bleibt stehen',
    kse_normalize_datetime_local('2026-09-29 19:30:00'), '2026-09-29 19:30:00');
check('Datum: ISO mit Sommerzeit-Offset',
    kse_normalize_datetime_local('2026-09-29T19:30:00+02:00'), '2026-09-29 19:30:00');
check('Datum: ISO mit Winterzeit-Offset',
    kse_normalize_datetime_local('2026-12-06T18:00:00+01:00'), '2026-12-06 18:00:00');
check('Datum: UTC wird in WP-Zeit umgerechnet',
    kse_normalize_datetime_local('2026-09-29T17:30:00Z'), '2026-09-29 19:30:00');
check('Datum: leer bleibt leer', kse_normalize_datetime_local(''), '');
check('Datum: Unsinn ergibt leer', kse_normalize_datetime_local('demnächst'), '');
check('Datum: Nullwert ergibt leer', kse_normalize_datetime_local('0000-00-00 00:00:00'), '');

/* ---------- Treffer-Reihenfolge: aktiver Eintrag vor Papierkorb ---------- */
// Nach dem Zusammenführen bleibt der Eintrag mit Bild stehen - auch mit höherer ID.
$GLOBALS['kse_test_post_status'] = [100 => 'trash', 200 => 'trash', 300 => 'publish'];
check('Papierkorb-Leichen landen hinten',
    kse_ei_order_by_status([300, 100, 200]), [300, 100, 200]);

$GLOBALS['kse_test_post_status'] = [10 => 'publish', 20 => 'draft', 30 => 'trash'];
check('Entwurf zählt als aktiv, nach ID sortiert',
    kse_ei_order_by_status([30, 20, 10]), [10, 20, 30]);

$GLOBALS['kse_test_post_status'] = [7 => 'trash', 8 => 'trash'];
check('nur Papierkorb: Reihenfolge nach ID',
    kse_ei_order_by_status([8, 7]), [7, 8]);

$GLOBALS['kse_test_post_status'] = [];
check('Doppelte IDs fallen raus', kse_ei_order_by_status([5, 5, 4]), [4, 5]);

echo "\n$pass Prüfungen bestanden, $fail fehlgeschlagen\n";
exit($fail > 0 ? 1 : 0);
