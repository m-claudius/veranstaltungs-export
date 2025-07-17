<?php
class SeevetalParser {
    public function fetch($term) {
        $results = [];
        $base = 'https://www.seevetal.de/regional/veranstaltungen/sucheplus2.html?schnellauswahl=0&suchwort=';
        $html = @file_get_contents($base . urlencode($term));
        if (!$html) return $results;

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Links zu den Detailseiten finden
        $nodes = $xpath->query("//div[contains(@class,'searchlist_item')]//a[contains(text(),'weiterlesen')]");
        foreach ($nodes as $a) {
            $href = $a->getAttribute('href');
            $url  = 'https://www.seevetal.de' . $href;
            $data = $this->parse_detail($url);
            $results[] = array_merge(['url' => $url], $data);
        }
        return $results;
    }

    private function parse_detail($url) {
        $html = @file_get_contents($url);
        if (!$html) return [];

        $dom = new DOMDocument();
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