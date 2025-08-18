<?php
if (!defined('ABSPATH')) exit;

/**
 * Empore Buchholz – Parser (V1)
 * Strategie:
 *  - Listenseite laden: https://empore-buchholz.de/karten-termine/
 *  - Alle internen Links sammeln, deduplizieren
 *  - Pro Link Detailseite laden, JSON-LD (@type Event) auswerten
 *  - Fallback: OG-Metas / Heuristiken
 *  - Events-Array zurückgeben
 */
class EmporeBuchholzParser {

    const LIST_URL = 'https://empore-buchholz.de/karten-termine/';
    const MAX_CANDIDATES = 40;

    protected function fetch($url) {
        $args = [
            'timeout' => 15,
            'headers' => [
                'User-Agent' => 'KSE-EventCrawler/1.0 (+WordPress)'
            ]
        ];
        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)) return '';
        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) return '';
        return (string) wp_remote_retrieve_body($res);
    }

    protected function abs_url($href, $base) {
        if (!$href) return '';
        if (strpos($href, 'http://')===0 || strpos($href, 'https://')===0) return $href;
        return rtrim($base, '/').'/'.ltrim($href, '/');
    }

    protected function same_host($url) {
        $h1 = parse_url(self::LIST_URL, PHP_URL_HOST);
        $h2 = parse_url($url, PHP_URL_HOST);
        return $h1 && $h2 && (strtolower($h1) === strtolower($h2));
    }

    /** Kandidaten-Links von der Listenseite */
    protected function collect_detail_links() {
        $html = $this->fetch(self::LIST_URL);
        if (!$html) return [];

        $links = [];
        // Simple Link-Extraktion
        if (preg_match_all('~<a[^>]+href=["\']([^"\']+)["\'][^>]*>~i', $html, $m)) {
            foreach ($m[1] as $href) {
                $url = $this->abs_url($href, self::LIST_URL);
                if (!$this->same_host($url)) continue;
                // offensichtliche Ausschlüsse
                if (strpos($url, '#') !== false) continue;
                if (preg_match('~\.(jpg|jpeg|png|webp|pdf|svg)$~i', $url)) continue;
                $links[] = $url;
            }
        }
        // grobe Heuristik: „termin“, „event“, „veranstaltung“ im Pfad bevorzugen
        $links = array_values(array_unique($links));
        usort($links, function($a,$b){
            $prio = function($u){
                $u = strtolower($u);
                $score = 0;
                foreach (['termin','termine','event','veranstaltung','kalender','programm'] as $kw) {
                    if (strpos($u, $kw)!==false) $score++;
                }
                return -$score; // mehr Treffer = weiter vorn
            };
            return $prio($a) <=> $prio($b);
        });

        // Limit
        return array_slice($links, 0, self::MAX_CANDIDATES);
    }

    /** JSON-LD Event aus HTML extrahieren */
    protected function parse_event_from_jsonld($html, $url) {
        $events = [];
        if (preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m)) {
            foreach ($m[1] as $blob) {
                $blob = trim($blob);
                $json = json_decode($blob, true);
                if (!$json) continue;

                $items = [];
                if (isset($json['@type'])) {
                    $items[] = $json;
                } elseif (isset($json['@graph']) && is_array($json['@graph'])) {
                    $items = array_merge($items, $json['@graph']);
                } elseif (is_array($json)) {
                    $items = $json;
                }

                foreach ((array)$items as $it) {
                    if (!is_array($it)) continue;
                    $types = (array)($it['@type'] ?? []);
                    $types = array_map('strtolower', $types);
                    if (!$types && isset($it['@type'])) $types = [strtolower($it['@type'])];
                    if (!in_array('event', $types, true)) continue;

                    $title = trim((string)($it['name'] ?? ''));
                    $start = trim((string)($it['startDate'] ?? ''));
                    $end   = trim((string)($it['endDate'] ?? ''));
                    $desc  = trim((string)($it['description'] ?? ''));

                    $image = '';
                    if (!empty($it['image'])) {
                        if (is_string($it['image'])) $image = $it['image'];
                        elseif (is_array($it['image'])) $image = (string) reset($it['image']);
                    }

                    $loc = '';
                    if (!empty($it['location']) && is_array($it['location'])) {
                        if (!empty($it['location']['name'])) $loc = (string)$it['location']['name'];
                        if (!empty($it['location']['address']) && is_array($it['location']['address'])) {
                            $addr = $it['location']['address'];
                            $addrStr = trim(($addr['streetAddress'] ?? '').' '.($addr['postalCode'] ?? '').' '.($addr['addressLocality'] ?? ''));
                            $loc = trim($loc.' '.preg_replace('/\s+/', ' ', $addrStr));
                        } elseif (is_string($it['location']['address'] ?? '')) {
                            $loc = trim($loc.' '.$it['location']['address']);
                        }
                        $loc = trim($loc);
                    }

                    if ($title || $start) {
                        $events[] = [
                            'title'       => $title,
                            'start'       => $start,
                            'end'         => $end,
                            'description' => $desc,
                            'image'       => esc_url_raw($image),
                            'location'    => $loc,
                            'source_url'  => esc_url_raw($url),
                        ];
                    }
                }
            }
        }
        return $events;
    }

    /** Fallback: OG Metas */
    protected function parse_event_from_og($html, $url) {
        $og = [];
        if (preg_match_all('~<meta\s+(?:property|name)=["\']og:([^"\']+)["\']\s+content=["\']([^"\']+)["\']\s*/?>~i', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) $og[strtolower($mm[1])] = $mm[2];
        }
        $title = $og['title'] ?? '';
        $desc  = $og['description'] ?? '';
        $img   = $og['image'] ?? '';
        if (!$title && !$desc) return [];
        return [[
            'title'       => $title,
            'start'       => '', // unbekannt
            'description' => $desc,
            'image'       => esc_url_raw($img),
            'location'    => '',
            'source_url'  => esc_url_raw($url),
        ]];
    }

    /** Detailseite parsen */
    protected function parse_detail($url) {
        $html = $this->fetch($url);
        if (!$html) return [];

        // 1) JSON-LD
        $evs = $this->parse_event_from_jsonld($html, $url);
        if ($evs) return $evs;

        // 2) OG-Fallback
        $evs = $this->parse_event_from_og($html, $url);
        if ($evs) return $evs;

        // 3) sehr grobe Heuristik (Titel aus <h1>)
        if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $html, $m)) {
            $title = wp_strip_all_tags($m[1]);
            return [[
                'title'       => trim($title),
                'start'       => '',
                'description' => '',
                'image'       => '',
                'location'    => '',
                'source_url'  => esc_url_raw($url),
            ]];
        }

        return [];
    }

    /** Öffentliche API */
    public function crawl($args = []) {
        $links = $this->collect_detail_links();
        $out = [];
        foreach ($links as $u) {
            $evs = $this->parse_detail($u);
            foreach ($evs as $e) {
                // nur sinnvolle Events
                if (!empty($e['title']) || !empty($e['start'])) $out[] = $e;
            }
            if (count($out) >= 50) break;
        }
        return $out;
    }
}
