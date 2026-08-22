<?php
/**
 * Plugin Name: Veranstaltungs Export mit Tag‑Matching
 * Description: Crawlt Events von Gemeinde Seevetal, matched Tags und zeigt alle Properties inkl. Bild.
 * Version: 2.2.0
 * Author: Matthias Clausen
 */

if (!defined('KSE_PLUGIN_DIR')) {
    define('KSE_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('KSE_PLUGIN_FILE')) define('KSE_PLUGIN_FILE', __FILE__);
//register_activation_hook(KSE_PLUGIN_FILE, 'kse_stats_install');

// Basis zuerst: Identitätsprüfung und Lauf-Sperre werden von den Importern gebraucht
require_once KSE_PLUGIN_DIR . 'includes/event-identity.php';
require_once KSE_PLUGIN_DIR . 'includes/run-lock.php';

require_once KSE_PLUGIN_DIR . 'crawler/MusikInAltenHeidekirchenParser.php';
require_once KSE_PLUGIN_DIR . 'crawler/SeevetalParser.php';
require_once KSE_PLUGIN_DIR . 'crawler/EmporeBuchholzParser.php';
require_once KSE_PLUGIN_DIR . 'includes/seevetal-admin.php';
require_once KSE_PLUGIN_DIR . 'crawler/BurgSeevetalParser.php';
require_once KSE_PLUGIN_DIR . 'includes/burg-seevetal-admin.php';
require_once KSE_PLUGIN_DIR . 'includes/miah-admin.php';
require_once KSE_PLUGIN_DIR . 'includes/burg-seevetal-cron.php';
require_once KSE_PLUGIN_DIR . 'admin/menu.php';
require_once KSE_PLUGIN_DIR . 'admin/settings.php';
require_once KSE_PLUGIN_DIR . 'includes/tec_import.php';
require_once KSE_PLUGIN_DIR . 'includes/stats.php';
require_once KSE_PLUGIN_DIR . 'includes/dedupe.php';
require_once KSE_PLUGIN_DIR . 'includes/dubletten-admin.php';
require_once KSE_PLUGIN_DIR . 'crawler/KulturvereinWinsenParser.php';


class SeevetalExporter {
    private $option_name = 've_search_terms';

public function __construct() {
    // add_action('admin_menu', [$this, 'add_admin_menu']); // ENTFERNEN
    add_action('admin_post_ve_save_terms', [$this, 'save_terms']);
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

    
}

new SeevetalExporter();