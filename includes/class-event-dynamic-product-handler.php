<?php
class Event_Dynamic_Product_Handler {
    public function __construct() {
        add_action('save_post_event', array($this, 'sync_event_products'), 10, 3);
        add_action('before_delete_post', array($this, 'cleanup_event_products'));
        add_action('wp_ajax_reindex_event_products', array($this, 'handle_product_reindex'));
        add_action('event_import_complete', array($this, 'sync_all_events_products'));

        // Einmalige Migration: bestehende Import-Produkte taggen
        if (get_option('_auto_sync_flag_migrated') !== '1') {
            add_action('init', array($this, 'migrate_auto_sync_flags'));
        }
    }

    public function migrate_auto_sync_flags() {
        $products = get_posts([
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_event_date',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        foreach ($products as $product_id) {
            update_post_meta($product_id, '_auto_synced_product', '1');
        }

        update_option('_auto_sync_flag_migrated', '1');
        error_log('[Event Sync] Migration: ' . count($products) . ' bestehende Produkte mit _auto_synced_product Flag versehen.');
    }

    public function sync_all_events_products() {
        error_log('Starting sync_all_events_products after import');

        if (function_exists('custom_events_maybe_extend_time_limit')) {
            custom_events_maybe_extend_time_limit();
        }

        $args = array(
            'post_type' => 'event',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        );

        $events = get_posts($args);

        foreach ($events as $event) {
            $this->batch_sync_event_products($event->ID);
            error_log("Synced products for event: " . $event->post_title);
        }

        error_log('Completed sync_all_events_products');
    }

    public function batch_sync_event_products($event_id) {
        $event_dates = get_post_meta($event_id, '_event_dates', true);
        if (empty($event_dates)) return;

        // Event-Typ bestimmt die Stock-Quelle:
        //   - Kurs (is_course)    → available_seats (max. Teilnehmer)
        //   - Workshop (is_workshop) → available_seats
        //   - sonst (Probetraining) → trail_seats
        $is_workshop = (int) get_post_meta($event_id, '_event_is_workshop', true) === 1;
        $is_course   = (int) get_post_meta($event_id, '_event_is_course', true) === 1;
        $use_available_seats = $is_workshop || $is_course;

        $this->cleanup_old_event_products($event_id, $event_dates);

        foreach ($event_dates as $date_info) {
            $this->create_or_update_event_product([
                'event_id'            => $event_id,
                'title'               => get_the_title($event_id) . ' - ' . $date_info['date'],
                'date'                => $date_info['date'],
                'start_time'          => $date_info['start_time'],
                'end_time'            => $date_info['end_time'],
                'trail_seats'         => isset($date_info['trail_seats']) ? (int) $date_info['trail_seats'] : 0,
                'available_seats'     => isset($date_info['available_seats']) ? (int) $date_info['available_seats'] : 0,
                'use_available_seats' => $use_available_seats,
            ]);
        }

        $this->update_event_products_price($event_id, get_post_meta($event_id, '_event_price', true));
    }

    public function sync_event_products($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if ($post->post_type !== 'event') return;
        if (wp_is_post_revision($post_id)) return;

        $this->batch_sync_event_products($post_id);
    }

    public function create_or_update_event_product($event_data) {
        $existing_product = $this->find_existing_product($event_data['event_id'], $event_data['date']);
        $price = get_post_meta($event_data['event_id'], '_event_price', true);

        // Stock-Quelle je nach Event-Typ wählen.
        // Kurse/Workshops: available_seats (max. Teilnehmer aus AcademyBoard).
        // Probetrainings: trail_seats (Probeplätze).
        $use_available_seats = !empty($event_data['use_available_seats']);
        $stock = $use_available_seats
            ? (int) ($event_data['available_seats'] ?? 0)
            : (int) ($event_data['trail_seats'] ?? 0);

        if ($existing_product) {
            $product_id = $existing_product;

            // Manuell angelegte Produkte nicht überschreiben
            if (get_post_meta($product_id, '_auto_synced_product', true) !== '1') {
                return $product_id;
            }

            wp_update_post([
                'ID' => $product_id,
                'post_title' => $event_data['title'],
                'post_status' => 'publish'
            ]);

            // API ist die einzige Wahrheit — alte _po_stock_protected-Flags
            // werden ignoriert und entfernt, damit Kurse/Workshops zuverlässig
            // auf available_seats zurückgesetzt werden.
            delete_post_meta($product_id, '_po_stock_protected');

            update_post_meta($product_id, '_stock', $stock);
            update_post_meta($product_id, '_manage_stock', 'yes');
            update_post_meta($product_id, '_stock_status', $stock > 0 ? 'instock' : 'outofstock');

            update_post_meta($product_id, '_price', $price);
            update_post_meta($product_id, '_regular_price', $price);
        } else {
            $product_id = wp_insert_post([
                'post_title' => $event_data['title'],
                'post_type' => 'product',
                'post_status' => 'publish'
            ]);

            update_post_meta($product_id, '_stock', $stock);
            update_post_meta($product_id, '_manage_stock', 'yes');
            update_post_meta($product_id, '_stock_status', $stock > 0 ? 'instock' : 'outofstock');

            update_post_meta($product_id, '_price', $price);
            update_post_meta($product_id, '_regular_price', $price);
            wp_set_object_terms($product_id, 'simple', 'product_type');
        }

        update_post_meta($product_id, '_auto_synced_product', '1');
        update_post_meta($product_id, '_event_id', $event_data['event_id']);
        update_post_meta($product_id, '_event_date', $event_data['date']);
        update_post_meta($product_id, '_virtual', 'yes');
        update_post_meta($product_id, '_sold_individually', 'no');

        wp_cache_delete($product_id, 'post_meta');
        wp_cache_delete($product_id, 'posts');

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }

        $term = term_exists('Events', 'product_cat');
        if (!$term) {
            $term = wp_insert_term('Events', 'product_cat');
        }
        if (!is_wp_error($term)) {
            wp_set_object_terms($product_id, $term['term_id'], 'product_cat');
        }

        return $product_id;
    }

    public function find_existing_product($event_id, $date) {
        $args = array(
            'post_type' => 'product',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_event_id',
                    'value' => $event_id
                ),
                array(
                    'key' => '_event_date',
                    'value' => $date
                )
            ),
            'posts_per_page' => 1,
            'fields' => 'ids'
        );

        $products = get_posts($args);
        return !empty($products) ? $products[0] : false;
    }

    private function update_event_products_price($event_id, $new_price) {
        $args = array(
            'post_type' => 'product',
            'meta_query' => array(
                array(
                    'key' => '_event_id',
                    'value' => $event_id
                )
            ),
            'posts_per_page' => -1
        );

        $products = get_posts($args);
        foreach ($products as $product) {
            // Manuell angelegte Produkte nicht überschreiben
            if (get_post_meta($product->ID, '_auto_synced_product', true) !== '1') {
                continue;
            }
            update_post_meta($product->ID, '_price', $new_price);
            update_post_meta($product->ID, '_regular_price', $new_price);
        }
    }

    private function cleanup_old_event_products($event_id, $current_dates) {
        $args = array(
            'post_type' => 'product',
            'meta_query' => array(
                array(
                    'key' => '_event_id',
                    'value' => $event_id
                )
            ),
            'posts_per_page' => -1
        );

        $products = get_posts($args);
        $current_dates_array = array_map(function($date) {
            return $date['date'];
        }, $current_dates);

        foreach ($products as $product) {
            $product_date = get_post_meta($product->ID, '_event_date', true);
            if (!in_array($product_date, $current_dates_array)) {
                wp_delete_post($product->ID, true);
            }
        }
    }

    public function cleanup_event_products($post_id) {
        if (get_post_type($post_id) !== 'event') return;

        $args = array(
            'post_type' => 'product',
            'meta_query' => array(
                array(
                    'key' => '_event_id',
                    'value' => $post_id
                )
            ),
            'posts_per_page' => -1
        );

        $products = get_posts($args);
        foreach ($products as $product) {
            wp_delete_post($product->ID, true);
        }
    }

    public function handle_product_reindex() {
        check_ajax_referer('reindex_products_nonce', 'nonce');

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 20;

        $args = array(
            'post_type' => 'event',
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC'
        );

        $events = get_posts($args);
        $total_events = wp_count_posts('event')->publish;

        foreach ($events as $event) {
            do_action('save_post_event', $event->ID, get_post($event->ID), true);
        }

        $done = ($offset + $batch_size) >= $total_events;

        wp_send_json(array(
            'success' => true,
            'done' => $done,
            'next_offset' => $offset + $batch_size,
            'total' => $total_events,
            'processed' => min($offset + $batch_size, $total_events)
        ));
    }
}
?>
