<?php
if (!defined('ABSPATH')) exit;

class SeevetalParser {

    // Öffentliche Render-Methode: zeigt pro Suchbegriff zunächst die gefundenen Detail-Links, dann die Event-Karten
public function display_results($terms_csv) {
    $out = '';
    $terms = $this->split_terms($terms_csv);

    foreach ($terms as $term) {
        $out .= '<h2>' . esc_html($term) . ' 🔍</h2>';

        // 1) Detail-Links sammeln
        $links = $this->collect_detail_links($term);
        $out  .= '<p><strong>Gefundene Detail-Links:</strong> ' . count($links) . '</p>';
        if (!empty($links)) {
            $out .= '<ul class="ve-links">';
            foreach ($links as $u) {
                // interner Admin-Link (Details) + externer Direktlink
                $admin_url = wp_nonce_url(
                    add_query_arg([
                        'page'      => 've_export',
                        've_action' => 'details',
                        'url'       => rawurlencode($u),
                    ], admin_url('admin.php')),
                    've_details'
                );
                $out .= '<li>'
                      . '<a href="' . esc_url($u) . '" target="_blank" rel="noopener noreferrer">Extern</a>'
                      . ' &nbsp;|&nbsp; '
                      . '<a href="' . esc_url($admin_url) . '">Details (Admin)</a>'
                      . '</li>';
            }
            $out .= '</ul>';
        } else {
            $out .= '<p>Keine Detail-Links gefunden.</p>';
        }

        // Optional: weiter wie bisher gleich alles parsen (kannst du auch weglassen, wenn du nur on-demand willst)
        $events = [];
        foreach ($links as $detail_url) {
            $data = $this->get_cached_detail($detail_url);
            if (!empty($data['title'])) {
                $data['url']  = $detail_url;
                $data['tags'] = $this->auto_match_tags(
                    $data['title'] . ' ' . $data['description'],
                    ['Kultur','Musik','Bildung','Führung','Kunst','Literatur','Schauspiel','Lesungen','Konzert']
                );
                $events[] = $data;
            }
        }

        if (!empty($events)) {
            foreach ($events as $e) {
                $out .= '<div class="ve-event">';
                $out .= '<h3>' . esc_html($e['title']) . '</h3>';
                if (!empty($e['image'])) {
                    $out .= '<img class="ve-event-img" src="' . esc_url($e['image']) . '" alt="">';
                }
                $out .= '<p><strong>Datum/Zeit:</strong> ' . esc_html($e['datetime']) . '</p>';
                $out .= '<p><strong>Ort:</strong> ' . esc_html($e['location']) . '</p>';
                $out .= '<p>' . nl2br(esc_html($e['description'])) . '</p>';
                if (!empty($e['tags'])) {
                    $out .= '<p><strong>Tags:</strong> ' . esc_html(implode(', ', $e['tags'])) . '</p>';
                }
                // Admin-Details-Shortcut
                $admin_url = wp_nonce_url(
                    add_query_arg([
                        'page'      => 've_export',
                        've_action' => 'details',
                        'url'       => rawurlencode($e['url']),
                    ], admin_url('admin.php')),
                    've_details'
                );
                $out .= '<p><a href="' . esc_url($e['url']) . '" target="_blank" rel="noopener">Extern</a> &nbsp;|&nbsp; <a href="' . esc_url($admin_url) . '">Details (Admin)</a></p>';
                $out .= '</div>';
            }
        } else {
            $out .= '<p>Keine Veranstaltungen geparst (nutze „Details (Admin)“ für Einzelansicht).</p>';
        }
    }
    return $out;
}

// einfacher Cache für Detailseiten
public function get_cached_detail($url) {
    $key = 've_evt_' . md5($url);
    $cached = get_transient($key);
    if (is_array($cached)) return $cached;
    $data = $this->parse_detail_page($url);
    if (!empty($data['title'])) {
        set_transient($key, $data, HOUR_IN_SECONDS); // 1h Cache
    }
    return $data;
}


    // ---- Sammle alle Detail-Links (Stufe 1) ----
    public function collect_detail_links($term) {
        $results = [];
        $base = 'https://www.seevetal.de/regional/veranstaltungen/sucheplus2.html';
        $url  = $base . '?' . http_build_query([
            'schnellauswahl' => 0,
            'suchwort'       => $term,
            'beginn_datum'   => '',
            'ende_datum'     => '',
            'ort'            => 0,
        ]);

        $html = $this->http_get($url);
        if (empty($html)) return $results;

        // DOM/XPath
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xp = new DOMXPath($dom);

        // Strategie A: direkte Detail-Links, z.B. .../regional/veranstaltungen/...-20200.html
        $nodes = $xp->query("//a[contains(@href,'/regional/veranstaltungen/') and contains(@href,'-20200')]");
        foreach ($nodes as $a) {
            $href = $a->getAttribute('href');
            if ($href) $results[] = $this->absolute_url($href);
        }

        // Strategie B: Fallback über 'weiterlesen'
        if (empty($results)) {
            $nodes = $xp->query("//a[contains(translate(normalize-space(string(.)),'ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÜ','abcdefghijklmnopqrstuvwxyzäöü'),'weiterlesen')]/@href");
            foreach ($nodes as $attr) {
                $href = $attr->value;
                if ($href) $results[] = $this->absolute_url($href);
            }
        }

        // Deduplizieren
        $results = array_values(array_unique($results));
        return $results;
    }

    // ---- Detail-Seite parsen (Stufe 2) ----
    public function parse_detail_page($url) {
        $out = [
            'title'        => '',
            'datetime'     => '',
            'location'     => '',
            'description'  => '',
            'image'        => '',
            'costs'        => '',
            'age'          => '',
            'registration' => '',
            'organizer'    => '',
        ];

        $html = $this->http_get($url);
        if (empty($html)) return $out;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        $xp  = new DOMXPath($dom);

        // Titel
        $out['title'] = trim($xp->evaluate("string(//h1)"));

        // Datum/Zeit (oft im meta description)
        $out['datetime'] = trim($xp->evaluate("string(//meta[@name='description']/@content)"));

        // Beschreibung (Haupttextbereich)
        $out['description'] = trim($xp->evaluate("string(//div[contains(@class,'main_text')])"));

        // Ort
        $out['location'] = trim($xp->evaluate("string(//*[contains(@class,'nolisicon-location')]/parent::*)"));

        // Bild (Open Graph)
        $out['image'] = trim($xp->evaluate("string(//meta[@property='og:image']/@content)"));

        // Zusätzliche Felder (weich, je nach Seite vorhanden)
        $out['costs']        = $this->soft_field($xp, 'Kosten');
        $out['age']          = $this->soft_field($xp, 'Alter');
        $out['registration'] = $this->soft_field($xp, 'Anmeldung');
        $out['organizer']    = $this->soft_field($xp, 'Veranstalter');

        return $out;
    }

    // ---- Hilfen ----

    private function http_get($url) {
        $resp = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'User-Agent'      => 'VE/1.5.5 (+wordpress; seevetal crawler)',
                'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
            ],
        ]);
        if (is_wp_error($resp)) return '';
        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) return '';
        return wp_remote_retrieve_body($resp);
    }

    private function split_terms($csv) {
        // Erlaubt Komma, Semikolon oder Leerzeichen als Trenner
        $parts = preg_split('/[,\s;]+/u', (string)$csv, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map('trim', $parts)));
    }

    private function soft_field(DOMXPath $xp, $label) {
        // Sucht <strong>Label</strong> und nimmt den nächsten Text/Node
        $val = trim($xp->evaluate("string(//strong[contains(.,'$label')]/following-sibling::node()[1])"));
        if (!$val) {
            // Alternativ: Zeile, in der der Label-Text vorkommt
            $val = trim($xp->evaluate("string(//*[contains(text(),'$label')]/following-sibling::node()[1])"));
        }
        return $val;
    }

    private function absolute_url($href) {
        $href = trim($href);
        if ($href === '') return '';
        if (strpos($href, 'http') === 0) return $href;
        return 'https://www.seevetal.de' . (strpos($href, '/') === 0 ? $href : '/'.$href);
    }

    private function auto_match_tags($text, $dict) {
        $found = [];
        $t = mb_strtolower($text, 'UTF-8');
        foreach ($dict as $d) {
            if ($d === '') continue;
            if (mb_stripos($t, mb_strtolower($d, 'UTF-8'), 0, 'UTF-8') !== false) {
                $found[] = $d;
            }
        }
        return array_values(array_unique($found));
    }
}
