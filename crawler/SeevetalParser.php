<?php
class SeevetalParser {
    public function fetch($term) {
        $results = [];

        // Vollständige Such-URL
        $baseUrl = 'https://www.seevetal.de/regional/veranstaltungen/sucheplus2.html';
        $query   = http_build_query([
            'schnellauswahl' => 0,
            'suchwort'       => $term,
            'beginn_datum'   => '',
            'ende_datum'     => '',
            'ort'            => 0,
        ]);
        $html = @file_get_contents("$baseUrl?$query");
        if (!$html) {
            return $results;
        }

        // Alle Detail-Links per Regex extrahieren
        if (preg_match_all('#href="(/regional/veranstaltungen/detail-[^"]+)"#', $html, $m)) {
            $hrefs = array_unique($m[1]);
        } else {
            $hrefs = [];
        }

        foreach ($hrefs as $href) {
            $url  = 'https://www.seevetal.de' . $href;
            $data = $this->parse_detail($url);
            if (!empty($data['title'])) {
                $results[] = array_merge(['url' => $url], $data);
            }
        }

        return $results;
    }

    private function parse_detail($url) {
        $html = @file_get_contents($url);
        if (!$html) return [];

        $dom   = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        return [
            'title'       => $this->get_text($xpath, "//h1"),
            'description' => $this->get_text($xpath, "//div[contains(@class,'text')]"),
            'date'        => $this->get_text($xpath, "//*[contains(text(),'Uhr')]"),
            'location'    => $this->get_text($xpath, "//*[contains(text(),'Veranstaltungsort')]/following-sibling::*"),
        ];
    }

    private function get_text($xpath, $query) {
        $n = $xpath->query($query);
        return ($n && $n->length) ? trim($n->item(0)->textContent) : '';
    }
}
