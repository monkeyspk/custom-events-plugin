<?php
/**
 * Backfill: _event_id auf bestehende WooCommerce-Order-Items nachtragen.
 *
 * Hintergrund: Der Checkout-Hook schrieb früher nur _event_title/_event_date/_event_product_id
 * aufs Order-Item, aber NICHT _event_id. Wenn das gebuchte Produkt später gelöscht wird,
 * lässt sich das Event nicht mehr auflösen → Self-Service-Umbuchung bricht ab.
 *
 * Dieses Script trägt _event_id nach: bevorzugt aus dem (noch existierenden) Produkt,
 * sonst über Titel-Match auf einen veröffentlichten 'event'-Post.
 *
 * Aufruf (pro Seite):
 *   wp eval-file tools/backfill-event-id.php            # Live-Lauf
 *   wp eval-file tools/backfill-event-id.php dry        # Nur zählen, nichts schreiben
 */

if (!defined('ABSPATH')) { exit; }

$dry = in_array('dry', (array) ($args ?? array()), true);

global $wpdb;

$item_ids = $wpdb->get_col("
    SELECT oi.order_item_id
    FROM {$wpdb->prefix}woocommerce_order_items oi
    WHERE oi.order_item_type = 'line_item'
      AND EXISTS (
        SELECT 1 FROM {$wpdb->prefix}woocommerce_order_itemmeta m1
        WHERE m1.order_item_id = oi.order_item_id AND m1.meta_key = '_event_title'
      )
      AND NOT EXISTS (
        SELECT 1 FROM {$wpdb->prefix}woocommerce_order_itemmeta m2
        WHERE m2.order_item_id = oi.order_item_id AND m2.meta_key = '_event_id' AND m2.meta_value <> ''
      )
");

$total = count($item_ids);
$via_product = 0;
$via_title   = 0;
$unresolved  = 0;

WP_CLI::log("Order-Items ohne _event_id: {$total}" . ($dry ? '  (DRY-RUN)' : ''));

foreach ($item_ids as $item_id) {
    $event_id = 0;

    // 1) Über das gebuchte Produkt (falls noch vorhanden).
    $product_id = (int) wc_get_order_item_meta($item_id, '_event_product_id', true);
    if ($product_id) {
        $event_id = (int) get_post_meta($product_id, '_event_id', true);
        if ($event_id) { $via_product++; }
    }

    // 2) Fallback: Live-Event über den gespeicherten Klassentitel.
    if (!$event_id) {
        $title = wc_get_order_item_meta($item_id, '_event_title_clean', true);
        if (empty($title)) {
            $title = wc_get_order_item_meta($item_id, '_event_title', true);
        }
        if (!empty($title)) {
            $matched = get_posts(array(
                'post_type'      => 'event',
                'post_status'    => 'publish',
                'title'          => $title,
                'posts_per_page' => 1,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ));
            if (!empty($matched)) {
                $event_id = (int) $matched[0];
                $via_title++;
            }
        }
    }

    if (!$event_id) {
        $unresolved++;
        continue;
    }

    if (!$dry) {
        wc_update_order_item_meta($item_id, '_event_id', $event_id);
    }
}

WP_CLI::success(sprintf(
    '%s: %d Items, via Produkt %d, via Titel %d, ungelöst %d',
    $dry ? 'DRY-RUN' : 'Backfill fertig',
    $total, $via_product, $via_title, $unresolved
));
