<?php
/**
 * Überlappungsschutz für die Crawler-Läufe
 *
 * WP-Cron wird durch Seitenaufrufe ausgelöst und kennt keine Laufzeitgrenze.
 * Ein Seevetal-Lauf holt ~30 Detailseiten mit je 25 s Timeout und lädt Bilder
 * nach - dauert er länger als das Cron-Intervall, startet der nächste Lauf,
 * während der erste noch schreibt. Beide finden dann "kein Event vorhanden"
 * und legen es an. Genau so entstehen Mehrfach-Einträge.
 *
 * Der Lock nutzt add_option(): der UNIQUE-Index auf option_name macht das
 * Setzen atomar, anders als get_option() + update_option().
 *
 * Public API:
 *   kse_lock_acquire(string $name, int $ttl = 1800): bool
 *   kse_lock_release(string $name): void
 *   kse_lock_break(string $name): void
 *   kse_lock_age(string $name): int   (-1 = kein Lock)
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('kse_lock_key')) {
function kse_lock_key(string $name): string
{
    return 'kse_lock_' . sanitize_key($name);
}}

if (!function_exists('kse_lock_acquire')) {
/**
 * @param int $ttl Nach dieser Zeit gilt ein Lock als verwaist (abgebrochener Lauf)
 *                 und wird übernommen.
 */
function kse_lock_acquire(string $name, int $ttl = 1800): bool
{
    $key = kse_lock_key($name);

    $existing = get_option($key, false);
    if ($existing !== false) {
        if ((time() - (int)$existing) < $ttl) {
            return false; // läuft noch
        }
        delete_option($key); // verwaist - übernehmen
    }

    // add_option() schlägt fehl, wenn die Option zwischenzeitlich angelegt wurde
    return (bool) add_option($key, time(), '', 'no');
}}

if (!function_exists('kse_lock_release')) {
function kse_lock_release(string $name): void
{
    delete_option(kse_lock_key($name));
}}

if (!function_exists('kse_lock_break')) {
/** Lock hart entfernen - für den manuellen "Jetzt starten"-Knopf */
function kse_lock_break(string $name): void
{
    delete_option(kse_lock_key($name));
}}

if (!function_exists('kse_lock_age')) {
/** Alter des Locks in Sekunden, -1 wenn keiner gesetzt ist */
function kse_lock_age(string $name): int
{
    $v = get_option(kse_lock_key($name), false);
    if ($v === false) return -1;
    return max(0, time() - (int)$v);
}}
