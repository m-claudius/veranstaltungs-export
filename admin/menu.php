<?php
if (!defined('ABSPATH')) exit;

// Diese Page wird vom bestehenden Menü-Callback eingebunden/aufgerufen
// Erwartet: Klasse SeevetalParser vorhanden (crawler/SeevetalParser.php)

$raw = isset($_POST['ve_terms']) ? wp_unslash($_POST['ve_terms']) : '';
$raw = is_string($raw) ? $raw : '';

$terms = preg_split('/[,\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
$terms = array_values(array_unique(array_map('trim', $terms)));

?>
<div class="wrap">
  <h1>Event Export – Testsuche</h1>

  <form method="post" action="">
    <?php wp_nonce_field('ve_terms_submit', 've_terms_nonce'); ?>
    <p>
      <label for="ve_terms"><strong>Suchwörter</strong> (durch Komma oder Leerzeichen trennen, z. B. <code>Musik, Kultur</code>)</label><br>
      <textarea id="ve_terms" name="ve_terms" rows="2" style="width:100%;max-width:720px"><?php echo esc_textarea($raw ?: 'Musik, Kultur'); ?></textarea>
    </p>
    <p><button class="button button-primary">Suchen</button></p>
  </form>

  <?php
  if (!empty($_POST) && isset($_POST['ve_terms_nonce']) && wp_verify_nonce($_POST['ve_terms_nonce'], 've_terms_submit')) {

      if (empty($terms)) {
          echo '<p><em>Bitte mindestens einen Suchbegriff eingeben.</em></p>';
      } else {
          require_once dirname(__DIR__) . '/crawler/SeevetalParser.php';
          $parser = new SeevetalParser();

          foreach ($terms as $term) {
              $dbg = $parser->debug_collect_detail_links($term);
              $http = $parser->get_last_http_meta(); // neu in Parser, siehe Schritt 2

              echo '<hr><h2>Suchbegriff: ' . esc_html($term) . '</h2>';
              echo '<p><strong>Abgefragte URL:</strong> <a href="' . esc_url($dbg['url']) . '" target="_blank" rel="noopener">' . esc_html($dbg['url']) . '</a></p>';
              if ($http) {
                  echo '<p><strong>HTTP:</strong> ' . intval($http['code']) . ' — <strong>Bytes:</strong> ' . intval($http['bytes']) . '</p>';
              } else {
                  echo '<p><strong>Bytes (Parser):</strong> ' . intval($dbg['bytes']) . '</p>';
              }

              $links = $dbg['links'] ?? [];
              echo '<p><strong>Gefundene Detail-Links:</strong> ' . count($links) . '</p>';

              if ($links) {
                  echo '<ol style="margin-left:1.25em">';
                  foreach ($links as $u) {
                      echo '<li><a href="' . esc_url($u) . '" target="_blank" rel="noopener">' . esc_html($u) . '</a></li>';
                  }
                  echo '</ol>';
              } else {
                  echo '<p><em>Keine Detail-Links gefunden.</em></p>';
              }
              $urls = $dbg['urls'] ?? [];
            if ($urls) {
                echo '<ul>';
                foreach ($urls as $tok => $u) {
                    echo '<li><strong>' . esc_html($tok) . ':</strong> <a href="' . esc_url($u) . '" target="_blank" rel="noopener">' . esc_html($u) . '</a></li>';
                }
                echo '</ul>';
            }
          }
      }
  }
  ?>
</div>
