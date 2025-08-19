<?php
/**
 * Admin-Menü & Seiten – Veranstaltungs-Export (neu)
 * WICHTIG:
 * - require_once passiert in veranstaltungs-export.php
 * - Dieses File lädt keine Includes nach, nutzt nur vorhandene Klassen/Funktionen.
 */
if (!defined('ABSPATH')) exit;

/* ===== Helpers ===== */
if (!function_exists('kse_html')) {
function kse_html($s){ return esc_html((string)$s); }
}
if (!function_exists('kse_array_get')) {
function kse_array_get($a,$k,$d=''){ return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d; }
}
if (!function_exists('kse_now_local')) {
function kse_now_local(){ return date_i18n('Y-m-d H:i:s'); }
}
if (!function_exists('kse_admin_notice')) {
function kse_admin_notice($msg,$type='info'){
    printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($type), wp_kses_post($msg));
}}
if (!function_exists('kse_excerpt_html')) {
function kse_excerpt_html($html, $len = 220) {
    $txt = wp_strip_all_tags((string)$html, true);
    $txt = trim(preg_replace('~\s+~', ' ', $txt));
    if (mb_strlen($txt) <= $len) return esc_html($txt);
    return esc_html(mb_substr($txt, 0, $len - 1)) . '…';
}}
if (!function_exists('kse_append_source_line')) {
function kse_append_source_line($desc, $srcUrl){
    $desc = (string)$desc;
    $src  = esc_url_raw($srcUrl);
    if (!$src) return $desc;
    // idempotent
    if (stripos($desc, 'Quelle: (C)') !== false && stripos($desc, $src) !== false) return $desc;
    $line = sprintf('<p><em>Quelle: (C) <a href="%s" target="_blank" rel="noopener">%s</a></em></p>',
        esc_url($src), esc_html($src));
    return $desc . "\n\n" . $line;
}}

/* ===== User-Cache (Transients) ===== */
if (!function_exists('kse_events_cache_key')) {
function kse_events_cache_key($source_slug){
    $uid = get_current_user_id();
    return 'kse_cache_' . sanitize_key($source_slug) . '_' . $uid;
}}
if (!function_exists('kse_cache_events')) {
function kse_cache_events($source_slug, array $events){
    $byUrl = [];
    foreach ($events as $ev){
        $u = esc_url_raw(kse_array_get($ev,'source_url',''));
        if (!$u) continue;
        $byUrl[$u] = $ev;
    }
    set_transient(kse_events_cache_key($source_slug), $byUrl, 30 * MINUTE_IN_SECONDS);
    return $byUrl;
}}
if (!function_exists('kse_get_cached_events')) {
function kse_get_cached_events($source_slug){
    $m = get_transient(kse_events_cache_key($source_slug));
    return is_array($m) ? $m : [];
}}

/* ===== Admin Menu ===== */
add_action('admin_menu', 'kse_register_menu');
function kse_register_menu(){
    $cap = 'manage_options';

    add_menu_page(
        'Veranstaltungs-Export', 'Veranstaltungs-Export',
        $cap, 'kse-dashboard', 'kse_render_dashboard',
        'dashicons-calendar-alt', 56
    );

    add_submenu_page('kse-dashboard','Gemeinde Seevetal','Gemeinde Seevetal',$cap,'kse-seevetal','kse_render_seevetal');
    add_submenu_page('kse-dashboard','Empore Buchholz','Empore Buchholz',$cap,'kse-empore','kse_render_empore');
    add_submenu_page('kse-dashboard','Musik in alten Heidekirchen','Musik in alten Heidekirchen',$cap,'kse-miah','kse_render_miah');
    add_submenu_page('kse-dashboard','Kulturverein Winsen','Kulturverein Winsen',$cap,'kse-winsen','kse_render_winsen_placeholder');

    if (function_exists('kse_settings_render_page')){
        add_submenu_page('kse-dashboard', 'Einstellungen', 'Einstellungen', $cap, 'kse-settings', 'kse_settings_render_page');
    }
    if (function_exists('kse_render_stats_admin')){
        add_submenu_page('kse-dashboard', 'Statistik', 'Statistik', $cap, 'kse-stats', 'kse_render_stats_admin');
    }
    add_submenu_page('kse-dashboard', 'Duplikate', 'Duplikate', $cap, 'kse-dupes', 'kse_render_dupes_admin');
}

/* ===== Dashboard ===== */
function kse_render_dashboard(){ ?>
<div class="wrap">
  <h1>Veranstaltungs-Export – Übersicht</h1>
  <p>Wähle links eine Quelle. Import erzeugt <strong>Events in The Events Calendar</strong>.
     Am Ende der Beschreibung wird automatisch eine <em>Quelle: (C) …</em>-Zeile angehängt.</p>

  <?php
  $checks = [
    'TEC Import-Funktionen'   => function_exists('kse_tec_upsert_event'),
    'Statistik-Funktionen'    => function_exists('kse_stats_log'),
    'SeevetalParser'          => class_exists('SeevetalParser'),
    'EmporeBuchholzParser'    => class_exists('EmporeBuchholzParser'),
    'MusikInAltenHeidekirchenParser' => class_exists('MusikInAltenHeidekirchenParser'),
  ];
  echo '<h2>Status</h2><table class="widefat striped" style="max-width:820px">';
  echo '<thead><tr><th>Komponente</th><th>Status</th></tr></thead><tbody>';
  foreach ($checks as $label=>$ok){
      printf('<tr><td>%s</td><td>%s</td></tr>', kse_html($label), $ok ? '✅ vorhanden' : '⚠️ fehlt');
  }
  echo '</tbody></table>';
  ?>
</div>
<?php }

/* ===== Quelle: Empore Buchholz ===== */
function kse_render_empore(){
    if (!class_exists('EmporeBuchholzParser')){
        kse_admin_notice('Parser <code>EmporeBuchholzParser</code> fehlt oder wurde nicht geladen.', 'error');
        return;
    }

    // IMPORT
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['kse_action']) && $_POST['kse_action']==='import_selected' && check_admin_referer('kse_import_empore')){
        if (!function_exists('kse_tec_upsert_event')){
            kse_admin_notice('Import-Layer <code>includes/tec_import.php</code> nicht verfügbar.', 'error');
        } else {
            $urls  = array_map('esc_url_raw', (array)($_POST['selected'] ?? []));
            $urls  = array_values(array_unique(array_filter($urls)));
            $cache = kse_get_cached_events('empore');

            echo '<div class="wrap"><h1>Import – Empore Buchholz</h1><ol>';
            foreach ($urls as $u){
                if (!isset($cache[$u])) continue;
                $ev = $cache[$u];
                $ev['description'] = kse_append_source_line(kse_array_get($ev,'description',''), kse_array_get($ev,'source_url',''));

                $res = kse_tec_upsert_event($ev, [
                    'default_duration_minutes' => 120,
                    'source_slug'     => 'empore',
                    'source_category' => 'Empore Buchholz',
                ]);

                $tec = !empty($res['permalink']) ? '<a href="'.esc_url($res['permalink']).'" target="_blank" rel="noopener">TEC #'.intval(kse_array_get($res,'post_id',0)).'</a>' : 'TEC (kein Link)';
                printf('<li>%s → %s</li>', kse_html(kse_array_get($ev,'title','(ohne Titel)')), $tec);
            }
            echo '</ol></div>';
        }
        return;
    }

    // CRAWL
    $events = [];
    if (isset($_GET['crawl']) && $_GET['crawl'] === '1'){
        $res = EmporeBuchholzParser::crawl(['limit'=>20]); // Test: 10
        if (!is_array($res)){
            kse_admin_notice('Crawler-Fehler (unerwartetes Ergebnis).', 'error');
        } else {
            $events = $res;
            kse_cache_events('empore', $events);
            kse_admin_notice(sprintf('%d Events geladen (%s).', count($events), kse_now_local()), 'success');
        }
    } else {
        $events = array_values(kse_get_cached_events('empore'));
    }

    ?>
    <div class="wrap">
      <h1>Empore Buchholz</h1>
      <p><a class="button button-primary" href="<?php echo esc_url(add_query_arg(['crawl'=>'1'])); ?>">Liste aktualisieren (10)</a></p>

      <?php if (empty($events)): ?>
        <p>Keine Events im Cache. Bitte „Liste aktualisieren“ klicken.</p>
      <?php else: ?>
        <form method="post">
          <?php wp_nonce_field('kse_import_empore'); ?>
          <input type="hidden" name="kse_action" value="import_selected" />
          <table class="widefat striped">
            <thead><tr>
              <th style="width:22px">✔</th>
              <th>Titel</th>
              <th>Datum/Zeit</th>
              <th>Ort</th>
              <th>Beschreibung</th> <!-- NEU -->
              <th>Bild</th>
              <th>Quelle</th>
            </tr></thead>
            <tbody>
              <?php foreach ($events as $ev): ?>
                <tr>
                  <td><input type="checkbox" name="selected[]" value="<?php echo esc_attr(kse_array_get($ev,'source_url','')); ?>"></td>
                  <td><?php echo kse_html(kse_array_get($ev,'title','')); ?></td>
                  <td><?php echo kse_html(kse_array_get($ev,'datetime','')); ?></td>
                  <td><?php echo kse_html(kse_array_get($ev,'location','')); ?></td>
                  <td><?php echo kse_excerpt_html(kse_array_get($ev,'description','')); ?></td>
                  <td><?php $img = esc_url(kse_array_get($ev,'image','')); echo $img ? '<img src="'.$img.'" style="max-height:36px;max-width:80px;object-fit:cover" />' : ''; ?></td>
                  <td><a href="<?php echo esc_url(kse_array_get($ev,'source_url','')); ?>" target="_blank" rel="noopener">Seite</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p><button class="button button-primary">Ausgewählte importieren</button></p>
        </form>
      <?php endif; ?>
    </div>
    <?php
}

/* ===== Quelle: Gemeinde Seevetal ===== */
function kse_render_seevetal(){
    if (!class_exists('SeevetalParser')){
        kse_admin_notice('Parser <code>SeevetalParser</code> fehlt oder wurde nicht geladen.', 'error');
        return;
    }

    // IMPORT
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['kse_action']) && $_POST['kse_action']==='import_selected' && check_admin_referer('kse_import_seevetal')){
        if (!function_exists('kse_tec_upsert_event')){
            kse_admin_notice('Import-Layer <code>includes/tec_import.php</code> nicht verfügbar.', 'error');
        } else {
            $urls  = array_map('esc_url_raw', (array)($_POST['selected'] ?? []));
            $urls  = array_values(array_unique(array_filter($urls)));
            $cache = kse_get_cached_events('seevetal');

            echo '<div class="wrap"><h1>Import – Gemeinde Seevetal</h1><ol>';
            foreach ($urls as $u){
                if (!isset($cache[$u])) continue;
                $ev = $cache[$u];
                $ev['description'] = kse_append_source_line(kse_array_get($ev,'description',''), kse_array_get($ev,'source_url',''));

                $res = kse_tec_upsert_event($ev, [
                    'default_duration_minutes' => 120,
                    'source_slug'     => 'seevetal',
                    'source_category' => 'Gemeinde Seevetal',
                ]);

                $tec = !empty($res['permalink']) ? '<a href="'.esc_url($res['permalink']).'" target="_blank" rel="noopener">TEC #'.intval(kse_array_get($res,'post_id',0)).'</a>' : 'TEC (kein Link)';
                printf('<li>%s → %s</li>', kse_html(kse_array_get($ev,'title','(ohne Titel)')), $tec);
            }
            echo '</ol></div>';
        }
        return;
    }

    // Begriffe laden
    $terms = get_option('ve_search_terms', []);
    if (!is_array($terms)) $terms = [];

    // CRAWL
    $results = [];
    if (isset($_GET['crawl']) && $_GET['crawl'] === '1'){
        $parser = new SeevetalParser();

        foreach ($terms as $term){
            $term = trim((string)$term);
            if ($term==='') continue;

            $links = method_exists($parser, 'collect_detail_links') ? $parser->collect_detail_links($term) : [];
            $links = array_values(array_unique(array_filter(array_map('esc_url_raw', (array)$links))));

            foreach ($links as $u){
                $ev = method_exists($parser,'get_cached_detail') ? $parser->get_cached_detail($u) : [];
                if (!$ev) continue;
                $ev['source_url'] = $u;
                $ev['term'] = $term;
                $results[] = $ev;
            }
        }

        kse_cache_events('seevetal', $results);
        kse_admin_notice(sprintf('%d Events geladen (%s).', count($results), kse_now_local()), 'success');
    } else {
        $results = array_values(kse_get_cached_events('seevetal'));
    }

    ?>
    <div class="wrap">
      <h1>Gemeinde Seevetal</h1>

      <h2>Suchbegriffe</h2>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1rem">
        <?php wp_nonce_field('ve_save_terms'); ?>
        <input type="hidden" name="action" value="ve_save_terms" />
        <textarea name="terms" rows="4" style="width:100%;max-width:680px" placeholder="Ein Begriff pro Zeile"><?php 
            echo esc_textarea(implode("\n", $terms)); 
        ?></textarea>
        <p><button class="button button-secondary">Begriffe speichern</button>
           <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['crawl'=>'1'])); ?>">Jetzt crawlen</a></p>
      </form>

      <h2>Ergebnisse</h2>
      <?php if (empty($results)): ?>
        <p>Keine Ergebnisse. Begriffe speichern und „Jetzt crawlen“ klicken.</p>
      <?php else: ?>
        <form method="post">
          <?php wp_nonce_field('kse_import_seevetal'); ?>
          <input type="hidden" name="kse_action" value="import_selected" />
          <table class="widefat striped">
            <thead><tr>
              <th style="width:22px">✔</th>
              <th>Begriff</th>
              <th>Titel</th>
              <th>Datum/Zeit</th>
              <th>Ort</th>
              <th>Beschreibung</th> <!-- NEU -->
              <th>Bild</th>
              <th>Quelle</th>
            </tr></thead>
            <tbody>
              <?php foreach ($results as $ev): ?>
                <tr>
                  <td><input type="checkbox" name="selected[]" value="<?php echo esc_attr(kse_array_get($ev,'source_url','')); ?>"></td>
                  <td><?php echo kse_html(kse_array_get($ev,'term','')); ?></td>
                  <td><?php echo kse_html(kse_array_get($ev,'title','')); ?></td>
                  <td><?php echo kse_html(kse_array_get($ev,'datetime','')); ?></td>
                  <td><?php echo kse_html(kse_array_get($ev,'location','')); ?></td>
                  <td><?php echo kse_excerpt_html(kse_array_get($ev,'description','')); ?></td>
                  <td><?php $img = esc_url(kse_array_get($ev,'image','')); echo $img ? '<img src="'.$img.'" style="max-height:36px;max-width:80px;object-fit:cover" />' : ''; ?></td>
                  <td><a href="<?php echo esc_url(kse_array_get($ev,'source_url','')); ?>" target="_blank" rel="noopener">Seite</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p><button class="button button-primary">Ausgewählte importieren</button></p>
        </form>
      <?php endif; ?>
    </div>
    <?php
}

/* ===== Quelle: Musik in alten Heidekirchen ===== */
function kse_render_miah(){
    if (!class_exists('MusikInAltenHeidekirchenParser')){
        kse_admin_notice('Parser <code>MusikInAltenHeidekirchenParser</code> fehlt oder wurde nicht geladen.', 'error');
        return;
    }

    // IMPORT
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['kse_action']) && $_POST['kse_action']==='import_selected' && check_admin_referer('kse_import_miah')){
        if (!function_exists('kse_tec_upsert_event')){
            kse_admin_notice('Import-Layer <code>includes/tec_import.php</code> nicht verfügbar.', 'error');
        } else {
            $urls  = array_map('esc_url_raw', (array)($_POST['selected'] ?? []));
            $urls  = array_values(array_unique(array_filter($urls)));
            $cache = kse_get_cached_events('miah');

            echo '<div class="wrap"><h1>Import – Musik in alten Heidekirchen</h1><ol>';
            foreach ($urls as $u){
                if (!isset($cache[$u])) continue;
                $ev = $cache[$u];
                $ev['description'] = kse_append_source_line(kse_array_get($ev,'description',''), kse_array_get($ev,'source_url',''));

                $res = kse_tec_upsert_event($ev, [
                    'default_duration_minutes' => 120,
                    'source_slug'     => 'miah',
                    'source_category' => 'Musik in alten Heidekirchen',
                ]);

                $tec = !empty($res['permalink']) ? '<a href="'.esc_url($res['permalink']).'" target="_blank" rel="noopener">TEC #'.intval(kse_array_get($res,'post_id',0)).'</a>' : 'TEC (kein Link)';
                printf('<li>%s → %s</li>', kse_html(kse_array_get($ev,'title','(ohne Titel)')), $tec);
            }
            echo '</ol></div>';
        }
        return;
    }

    // CRAWL
    $events = [];
    if (isset($_GET['crawl']) && $_GET['crawl'] === '1'){
        $res = MusikInAltenHeidekirchenParser::crawl([]);
        if (!is_array($res)){
            kse_admin_notice('Crawler-Fehler (unerwartetes Ergebnis).', 'error');
        } else {
            $events = $res;
            kse_cache_events('miah', $events);
            kse_admin_notice(sprintf('%d Events geladen (%s).', count($events), kse_now_local()), 'success');
        }
    } else {
        $events = array_values(kse_get_cached_events('miah'));
    }

    ?>
    <div class="wrap">
      <h1>Musik in alten Heidekirchen</h1>
      <p><a class="button button-primary" href="<?php echo esc_url(add_query_arg(['crawl'=>'1'])); ?>">Liste aktualisieren</a></p>

      <?php if (empty($events)): ?>
        <p>Keine Events im Cache. Bitte „Liste aktualisieren“ klicken.</p>
      <?php else: ?>
        <form method="post">
          <?php wp_nonce_field('kse_import_miah'); ?>
          <input type="hidden" name="kse_action" value="import_selected" />
          <table class="widefat striped">
            <thead><tr>
              <th style="width:22px">✔</th>
              <th>Titel</th>
              <th>Datum/Zeit</th>
              <th>Ort</th>
              <th>Beschreibung</th> <!-- NEU -->
              <th>Bild</th>
              <th>Quelle</th>
            </tr></thead>
            <tbody>
              <?php foreach ($events as $ev): ?>
                <tr>
                  <td><input type="checkbox" name="selected[]" value="<?php echo esc_attr(kse_array_get($ev,'source_url','')); ?>"></td>
                  <td><?php echo kse_html(kse_array_get($ev,'title','')); ?></td>
                  <td><?php echo kse_html(kse_array_get($ev,'datetime','')); ?></td>
                  <td><?php echo kse_html(kse_array_get($ev,'location','')); ?></td>
                  <td><?php echo kse_excerpt_html(kse_array_get($ev,'description','')); ?></td>
                  <td><?php $img = esc_url(kse_array_get($ev,'image','')); echo $img ? '<img src="'.$img.'" style="max-height:36px;max-width:80px;object-fit:cover" />' : ''; ?></td>
                  <td><a href="<?php echo esc_url(kse_array_get($ev,'source_url','')); ?>" target="_blank" rel="noopener">Seite</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p><button class="button button-primary">Ausgewählte importieren</button></p>
        </form>
      <?php endif; ?>
    </div>
    <?php
}

/* ===== Platzhalter: Kulturverein Winsen ===== */
function kse_render_winsen_placeholder(){ ?>
  <div class="wrap">
    <h1>Kulturverein Winsen</h1>
    <p>Parser/Integration folgt. Diese Seite ist ein Platzhalter.</p>
  </div>
<?php }

/* ===== Duplikate ===== */
function kse_render_dupes_admin(){
    if (!function_exists('kse_find_duplicates_groups') || !function_exists('kse_merge_event_group')){
        kse_admin_notice('Dedupe-Funktionen nicht verfügbar (<code>includes/dedupe.php</code>).', 'error');
        return;
    }

    // MERGE
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['kse_action']) && $_POST['kse_action']==='merge' && check_admin_referer('kse_merge_dupes')){
        $groups = (array)($_POST['group'] ?? []);
        $merged = 0;
        echo '<div class="wrap"><h1>Duplikate zusammenführen – Ergebnis</h1><ol>';
        foreach ($groups as $ids){
            $ids = array_values(array_unique(array_map('intval', (array)$ids)));
            if (count($ids) < 2) continue;
            $res = kse_merge_event_group($ids);
            if (!empty($res['merged'])){
                $merged++;
                printf('<li>Gruppe -> Primary %d, gelöscht: %s</li>', intval(kse_array_get($res,'primary',0)), implode(',', array_map('intval', (array)kse_array_get($res,'trashed',[]))));
            }
        }
        echo '</ol>';
        kse_admin_notice(sprintf('%d Gruppen zusammengeführt.', $merged), 'success');
        echo '</div>';
        return;
    }

    // LIST
    $groups = kse_find_duplicates_groups(180); // 6 Monate
    ?>
    <div class="wrap">
      <h1>Duplikate</h1>
      <?php if (empty($groups)): ?>
        <p>Keine potenziellen Duplikate gefunden (Zeitraum: 180 Tage).</p>
      <?php else: ?>
        <form method="post">
          <?php wp_nonce_field('kse_merge_dupes'); ?>
          <input type="hidden" name="kse_action" value="merge" />
          <?php foreach ($groups as $normTitle => $items): $gkey = md5($normTitle); ?>
            <div class="postbox" style="padding:0 16px 12px;margin-top:16px">
              <h2 class="hndle" style="margin:12px 0"><?php echo kse_html($normTitle); ?></h2>
              <table class="widefat striped">
                <thead><tr>
                  <th style="width:22px">✔</th>
                  <th>Event</th>
                  <th>Datum</th>
                  <th>Quelle</th>
                  <th>Link</th>
                </tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                  <tr>
                    <td><input type="checkbox" name="group[<?php echo esc_attr($gkey); ?>][]" value="<?php echo intval(kse_array_get($it,'ID',0)); ?>" checked></td>
                    <td><?php echo kse_html(kse_array_get($it,'title','')); ?> (#<?php echo intval(kse_array_get($it,'ID',0)); ?>)</td>
                    <td><?php echo kse_html(kse_array_get($it,'start','')); ?></td>
                    <td><?php echo kse_html(kse_array_get($it,'source','')); ?></td>
                    <td><a href="<?php echo esc_url(get_permalink(intval(kse_array_get($it,'ID',0)))); ?>" target="_blank" rel="noopener">ansehen</a></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endforeach; ?>
          <p><button class="button button-primary">Ausgewählte Gruppen zusammenführen</button></p>
        </form>
      <?php endif; ?>
    </div>
    <?php
}
