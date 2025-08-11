<?php
/**
 * Plugin Name: Veranstaltungs Export mit Tag‑Matching X
 * Description: Crawlt Events von Gemeinde Seevetal, matched Tags und zeigt alle Properties inkl. Bild.
 * Version: 2.0.1
 * Author: Matthias Clausen
 */

if (!defined('ABSPATH')) {
    exit;
}

class SeevetalExporter {
    private $option_name = 've_search_terms';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_ve_save_terms', [$this, 'save_terms']);
        add_action('admin_post_ve_import_tec', ['SeevetalExporter', 'handle_import_to_tec']);

    }

    public function add_admin_menu() {
        error_log('SeevetalExporter::add_admin_menu() gestartet');
        add_menu_page(
            'Veranstaltungsquellen',       // Page title
            'Event Export',                 // Menu title
            'read',                         // Capability (temporär auf read gesetzt)
            've-export',                    // Menu slug (hyphen statt underscore)
            [$this, 'render_admin_page'],   // Callback
            'dashicons-calendar-alt'        // Icon
            // Keine Position: WP wählt automatisch
        );
    }

    public function save_terms() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('ve_save_terms');
        $terms = sanitize_text_field($_POST['search_terms']);
        update_option($this->option_name, $terms);
        wp_redirect(admin_url('admin.php?page=ve-export'));
        exit;
    }

    public function render_admin_page() {
        if (!current_user_can('read')) return;
        $terms = get_option($this->option_name, 'kultur, musik');
        ?>
        <div class="wrap">
            <h1>Veranstaltungsquellen</h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ve_save_terms'); ?>
                <input type="hidden" name="action" value="ve_save_terms">
                <textarea name="search_terms" rows="4" style="width:100%;"><?php echo esc_textarea($terms); ?></textarea><br>
                <button class="button button-primary" type="submit">Speichern &amp; Anzeigen</button>
            </form>
            <?php if ($terms): ?>
                <?php $this->crawl_and_display($terms); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function crawl_and_display($terms) {
        echo '<div style="margin-top:20px;">';
        $raw = array_filter(array_map('trim', explode(',', $terms)));
        foreach ($raw as $term) {
            echo '<h2>' . esc_html($term) . ' 🔍</h2>';
            $events = $this->fetch_events_for_term($term);
            if (empty($events)) {
                echo '<p>Keine Veranstaltungen gefunden.</p>';
            } else {
                foreach ($events as $e) {
                    echo '<div style="margin-bottom:20px;border-bottom:1px solid #ddd;padding-bottom:10px;">';
                    echo '<h3>' . esc_html($e['title']) . '</h3>';
                    echo '<p><strong>Datum:</strong> ' . esc_html($e['datetime']) . '</p>';
                    echo '<p><strong>Ort:</strong> ' . esc_html($e['location']) . '</p>';
                    echo '<p><strong>Beschreibung:</strong><br>' . nl2br(esc_html($e['description'])) . '</p>';
                    if (!empty($e['image'])) {
                        echo '<p><img src="' . esc_url($e['image']) . '" style="max-width:200px;float:right;margin:0 0 10px 10px;" /></p>';
                    }
                    echo '<p><strong>Kosten:</strong> ' . esc_html($e['costs']) . '</p>';
                    echo '<p><strong>Alter:</strong> ' . esc_html($e['age']) . '</p>';
                    echo '<p><strong>Anmeldung:</strong> ' . esc_html($e['registration']) . '</p>';
                    echo '<p><strong>Veranstalter:</strong> ' . esc_html($e['organizer']) . '</p>';
                    $tags = $this->auto_match_tags($e['description'], ['Kultur','Musik','Bildung','Führung','Lesung','Konzert']);
                    echo '<p><strong>Tags:</strong> ' . esc_html(join(', ', $tags)) . '</p>';
                    echo '<p><a href="' . esc_url($e['url']) . '" target="_blank">Detail‑Seite ansehen</a></p>';
                    echo '</div>';
                }
            }
        }
        echo '</div>';
    }

    private function fetch_events_for_term($term) {
        $results = [];
        $base = 'https://www.seevetal.de/regional/veranstaltungen/sucheplus2.html';
        $url = $base . '?' . http_build_query([
            'schnellauswahl'=>0,'suchwort'=>$term,'beginn_datum'=>'','ende_datum'=>'','ort'=>0
        ]);
        error_log("DEBUG: Abruf Suchseite $url");
        $response = wp_remote_get($url, ['timeout'=>15]);
        $html = wp_remote_retrieve_body($response);
        if (empty($html)) return $results;
        libxml_use_internal_errors(true);
        $dom = new DOMDocument(); @$dom->loadHTML(mb_convert_encoding($html,'HTML-ENTITIES','UTF-8'));
        $xpath = new DOMXPath($dom);
        $links = $xpath->query("//a[contains(@href,'/regional/veranstaltungen/') and contains(@href,'-9100')]");
        if ($links->length===0) {
            error_log('DEBUG: Keine Detail-Links gefunden.');
            return $results;
        }
        foreach ($links as $n) {
            $href = $n instanceof DOMAttr ? $n->value : $n->getAttribute('href');
            $detail = $this->absolute_url($href);
            error_log("DEBUG: Detail-Link $detail");
            $data = $this->parse_detail_page($detail);
            if (!empty($data['title'])) {
                $data['url'] = $detail;
                $results[] = $data;
            }
        }
        return $results;
    }

    private function parse_detail_page($url) {
        $out = ['title'=>'','datetime'=>'','location'=>'','description'=>'','costs'=>'','age'=>'','registration'=>'','organizer'=>'','image'=>''];
        $response = wp_remote_get($url, ['timeout'=>15]);
        $html = wp_remote_retrieve_body($response);
        if (empty($html)) return $out;
        libxml_use_internal_errors(true);
        $dom = new DOMDocument(); @$dom->loadHTML(mb_convert_encoding($html,'HTML-ENTITIES','UTF-8'));
        $xpath = new DOMXPath($dom);
        $out['title'] = trim($xpath->evaluate("string(//h1)"));
        $out['datetime'] = trim($xpath->evaluate("string(//meta[@name='description']/@content)"));
        $out['description'] = trim($xpath->evaluate("string(//div[contains(@class,'main_text')])"));
        $out['location'] = trim($xpath->evaluate("string(//*[contains(@class,'nolisicon-location')]/parent::*)"));
        $out['costs'] = trim($xpath->evaluate("string(//strong[contains(.,'Kosten')]/following-sibling::text()[1])"));
        $out['age'] = trim($xpath->evaluate("string(//strong[contains(.,'Alter')]/following-sibling::text()[1])"));
        $out['registration'] = trim($xpath->evaluate("string(//strong[contains(.,'Anmeldung')]/following-sibling::text()[1])"));
        $out['organizer'] = trim($xpath->evaluate("string(//strong[contains(.,'Veranstalter')]/following-sibling::*)"));
        $out['image'] = trim($xpath->evaluate("string(//meta[@property='og:image']/@content)"));
        return $out;
    }

    private function absolute_url($href) {
        return strpos($href,'http')===0 ? $href : 'https://www.seevetal.de' . $href;
    }

    private function auto_match_tags($text, $dict) {
        $found = [];
        foreach ($dict as $t) {
            if (stripos($text, $t) !== false) {
                $found[] = $t;
            }
        }
        return $found;
    }

        /** Einzelnes Event aus Detail-URL parsen und in TEC importieren */
    public static function handle_import_to_tec() {
        if ( ! current_user_can('manage_options')) {
            wp_die('Keine Berechtigung');
        }

        check_admin_referer('ve_import_tec');

        $detail_url = isset($_GET['url']) ? esc_url_raw($_GET['url']) : '';
        if (!$detail_url) {
            wp_safe_redirect( admin_url('admin.php?page=veranstaltungs-export&ve_msg=no_url') );
            exit;
        }

        // Parser laden
        require_once __DIR__ . '/crawler/SeevetalParser.php';
        $parser = new SeevetalParser();

        // <- WICHTIG: benutze hier die richtige Methode/Signatur aus deinem Parser!
        // Erwartet wird ein Array im JSON-Schema aus unserem letzten Schritt.
        // Beispielname (bitte ggf. anpassen):
        $event = $parser->parse_detail_to_json($detail_url);

        if (empty($event) || !is_array($event)) {
            wp_safe_redirect( add_query_arg('ve_msg','parse_failed', wp_get_referer() ?: admin_url('admin.php?page=veranstaltungs-export')) );
            exit;
        }

        $summary = Seevetal_TEC_Importer::import_batch([$event], [
            'dry_run' => false,
            'log'     => true,
        ]);

        $q = [
            've_msg'   => 'import_done',
            'imported' => $summary['imported'] ?? 0,
            'updated'  => $summary['updated']  ?? 0,
            'failed'   => $summary['failed']   ?? 0,
        ];

        wp_safe_redirect( add_query_arg($q, wp_get_referer() ?: admin_url('admin.php?page=veranstaltungs-export')) );
        exit;
    }

}

new SeevetalExporter();
