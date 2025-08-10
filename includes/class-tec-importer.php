<?php
// includes/class-tec-importer.php

if (!defined('ABSPATH')) exit;

class Seevetal_TEC_Importer {

    /** Haupt-Einstieg: mehrere Events importieren */
    public static function import_batch(array $events, array $args = []) : array {
        $defaults = [
            'dry_run' => false,   // true = nur prüfen, nichts anlegen
            'log'     => true,    // einfache Log-Ausgabe mit error_log
        ];
        $args = array_merge($defaults, $args);

        $summary = [
            'total'    => count($events),
            'imported' => 0,
            'updated'  => 0,
            'failed'   => 0,
            'errors'   => [],   // [idx => message]
            'ids'      => [],   // [idx => post_id]
        ];

        foreach ($events as $idx => $payload) {
            try {
                self::validate_payload($payload);

                if ($args['dry_run']) {
                    // Nur Validierung: ok
                    $summary['ids'][$idx] = 0;
                    continue;
                }

                $result = self::import_one($payload);
                $summary['ids'][$idx] = $result['id'];
                $summary[$result['action']]++;

            } catch (Throwable $e) {
                $summary['failed']++;
                $summary['errors'][$idx] = $e->getMessage();
                if ($args['log']) error_log('[VE Import] Failed: '.$e->getMessage());
            }
        }

        if ($args['log']) {
            error_log(sprintf('[VE Import] batch done: total=%d, imported=%d, updated=%d, failed=%d',
                $summary['total'], $summary['imported'], $summary['updated'], $summary['failed']));
        }

        return $summary;
    }

    /** Einzelnes Event importieren/aktualisieren */
    public static function import_one(array $data) : array {
        // 1) Dedupe per source id
        $source_id  = $data['source']['id']  ?? '';
        $source_url = $data['source']['url'] ?? '';

        $event_id = 0;
        if ($source_id !== '') {
            $existing = get_posts([
                'post_type'   => 'tribe_events',
                'post_status' => 'any',
                'meta_key'    => '_ve_source_id',
                'meta_value'  => $source_id,
                'numberposts' => 1,
                'fields'      => 'ids',
            ]);
            if ($existing) $event_id = (int)$existing[0];
        }

        // 2) Venue / Organizer upserten
        $venue_id = self::upsert_venue($data['venue'] ?? []);
        $org_id   = self::upsert_organizer($data['organizer'] ?? []);

        // 3) Event erstellen/aktualisieren
        $postarr = [
            'ID'           => $event_id,
            'post_type'    => 'tribe_events',
            'post_status'  => 'publish',
            'post_title'   => wp_strip_all_tags($data['event']['title'] ?? ''),
            'post_content' => $data['event']['description'] ?? '',
        ];
        $is_update = (bool)$event_id;
        $event_id  = $is_update ? wp_update_post($postarr, true) : wp_insert_post($postarr, true);
        if (is_wp_error($event_id)) throw new RuntimeException($event_id->get_error_message());

        // 4) Zeitfelder / Meta
        $tz = new DateTimeZone($data['event']['timezone'] ?? 'Europe/Berlin');
        $start = new DateTime($data['event']['start'], $tz);
        update_post_meta($event_id, '_EventTimezone',  $tz->getName());
        update_post_meta($event_id, '_EventStartDate', $start->format('Y-m-d H:i:s'));

        $end_raw = $data['event']['end'] ?? null;
        if ($end_raw) {
            $end = new DateTime($end_raw, $tz);
            update_post_meta($event_id, '_EventEndDate', $end->format('Y-m-d H:i:s'));
        } else {
            delete_post_meta($event_id, '_EventEndDate');
        }

        update_post_meta($event_id, '_EventURL',  $data['event']['external_url'] ?? '');
        update_post_meta($event_id, '_EventCost', $data['event']['cost']         ?? '');

        if ($venue_id) update_post_meta($event_id, '_EventVenueID',     $venue_id);
        if ($org_id)   update_post_meta($event_id, '_EventOrganizerID', $org_id);

        // 5) Kategorien
        if (!empty($data['event']['categories']) && is_array($data['event']['categories'])) {
            wp_set_object_terms($event_id, $data['event']['categories'], 'tribe_events_cat');
        }

        // 6) Bild
        if (!empty($data['event']['image_url'])) {
            $attach_id = self::sideload_image($data['event']['image_url'], $event_id);
            if ($attach_id) set_post_thumbnail($event_id, $attach_id);
        }

        // 7) Source-Meta für Dedupe
        update_post_meta($event_id, '_ve_source_id',  $source_id);
        update_post_meta($event_id, '_ve_source_url', $source_url);

        return [
            'id'     => $event_id,
            'action' => $is_update ? 'updated' : 'imported',
        ];
    }

    /** Payload grob validieren */
    private static function validate_payload(array $d) : void {
        if (empty($d['event']['title']))  throw new InvalidArgumentException('event.title fehlt');
        if (empty($d['event']['start']))  throw new InvalidArgumentException('event.start fehlt');
        if (empty($d['venue']['name']))   throw new InvalidArgumentException('venue.name fehlt');
        if (empty($d['source']['id']))    throw new InvalidArgumentException('source.id fehlt');
    }

    /** Venue upserten */
    private static function upsert_venue(array $v) : int {
        if (empty($v['name'])) return 0;
        $existing = get_posts([
            'post_type'   => 'tribe_venue',
            'title'       => $v['name'],
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);
        $id = $existing ? (int)$existing[0] : wp_insert_post([
            'post_type'   => 'tribe_venue',
            'post_status' => 'publish',
            'post_title'  => wp_strip_all_tags($v['name']),
        ]);
        if (is_wp_error($id)) return 0;

        update_post_meta($id, '_VenueAddress', $v['street']      ?? '');
        update_post_meta($id, '_VenueCity',    $v['city']        ?? '');
        update_post_meta($id, '_VenueState',   $v['state']       ?? '');
        update_post_meta($id, '_VenueCountry', $v['country']     ?? 'DE');
        update_post_meta($id, '_VenueZip',     $v['postal_code'] ?? '');
        return (int)$id;
    }

    /** Organizer upserten */
    private static function upsert_organizer(array $o) : int {
        if (empty($o['name'])) return 0;
        $existing = get_posts([
            'post_type'   => 'tribe_organizer',
            'title'       => $o['name'],
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);
        $id = $existing ? (int)$existing[0] : wp_insert_post([
            'post_type'   => 'tribe_organizer',
            'post_status' => 'publish',
            'post_title'  => wp_strip_all_tags($o['name']),
        ]);
        if (is_wp_error($id)) return 0;

        update_post_meta($id, '_OrganizerPhone',   $o['phone']   ?? '');
        update_post_meta($id, '_OrganizerEmail',   $o['email']   ?? '');
        update_post_meta($id, '_OrganizerWebsite', $o['website'] ?? '');
        return (int)$id;
    }

    /** Bild in Mediathek laden */
    private static function sideload_image(string $url, int $parent = 0) : int {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return 0;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url);
        if (is_wp_error($tmp)) return 0;

        $file_array = [
            'name'     => basename(parse_url($url, PHP_URL_PATH)),
            'tmp_name' => $tmp,
        ];
        $id = media_handle_sideload($file_array, $parent);
        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']);
            return 0;
        }
        return (int)$id;
    }
}
