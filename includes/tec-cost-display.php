<?php
/**
 * Anzeige "Kostenlos" unterdrücken
 *
 * Belegt im Quellcode von The Events Calendar 6.9.1:
 *
 *   Tribe__Cost_Utils::maybe_replace_cost_with_free()
 *     -> ist der Preis numerisch und formatiert sich zu "0.00",
 *        wird daraus esc_html__( 'Free', 'tribe-common' ), deutsch "Kostenlos".
 *
 *   Tribe__Events__Cost_Utils::get_event_costs()
 *     -> leere Werte werden herausgefiltert; ein leeres Preisfeld erzeugt
 *        also gar keine Ausgabe.
 *
 * Eine Null im Preis ist damit die einzige Quelle des Wortes. Sie kann von
 * Hand eingetragen sein oder von Event Tickets stammen, das bei Tickets und
 * Reservierungen ohne Preis eine 0 hinterlegt - der Filter hier greift in
 * beiden Fällen, weil er am Ausgabewert ansetzt.
 *
 * tribe_get_cost() ist der letzte Filter vor der Ausgabe:
 *   return apply_filters( 'tribe_get_cost', $cost, $post_id, $with_currency_symbol );
 * tribe_get_formatted_cost() ruft tribe_get_cost() auf, ist also mit abgedeckt.
 *
 * Echte Preise bleiben unangetastet - nur was sich zu null ausrechnet,
 * verschwindet.
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('kse_cost_reads_as_free')) {
/**
 * Läuft diese Preisangabe auf "kostenlos" hinaus?
 *
 * @param string $cost       Wert, wie TEC ihn ausgeben würde ("0", "0,00 €", "Kostenlos", "15 €")
 * @param string $free_label Das übersetzte Wort, das TEC für eine Null einsetzt
 */
function kse_cost_reads_as_free(string $cost, string $free_label = 'Free'): bool
{
    $cost = trim($cost);
    if ($cost === '') return false;          // kein Preis gesetzt: TEC zeigt ohnehin nichts

    // Genau der String, den maybe_replace_cost_with_free() erzeugt hätte.
    // Bewusst kein fest verdrahtetes "Kostenlos", damit eine geänderte
    // Übersetzung den Filter nicht aushebelt.
    if ($free_label !== '' && strcasecmp($cost, trim($free_label)) === 0) return true;

    // Zahlenwert herausschälen: Währungssymbole und Text weg
    $plain = preg_replace('~[^0-9,.\-]~u', '', $cost);
    $plain = trim((string)$plain);
    if ($plain === '') return false;

    $plain = str_replace(',', '.', $plain);
    if (!is_numeric($plain)) return false;

    return number_format((float)$plain, 2, '.', ',') === '0.00';
}}

if (!function_exists('kse_filter_hide_zero_cost')) {
/**
 * @param mixed $cost
 * @return mixed
 */
function kse_filter_hide_zero_cost($cost, $post_id = null, $with_currency_symbol = false)
{
    /**
     * Abschalten per Filter, falls "Kostenlos" doch einmal erwünscht ist.
     *
     * @param bool $enabled
     * @param int|null $post_id
     */
    if (!apply_filters('kse_hide_zero_cost', true, $post_id)) {
        return $cost;
    }

    if (!is_string($cost) && !is_numeric($cost)) return $cost;

    $free = function_exists('esc_html__') ? (string) esc_html__('Free', 'tribe-common') : 'Free';

    return kse_cost_reads_as_free((string)$cost, $free) ? '' : $cost;
}}

add_filter('tribe_get_cost', 'kse_filter_hide_zero_cost', 20, 3);
