<?php
// General utility functions used across the plugin can be defined here.


add_action('wp_enqueue_scripts', 'woocommerce_ajax_add_to_cart_js', 99);
function woocommerce_ajax_add_to_cart_js() {
    if (function_exists('is_product') && !is_product()) {
        wp_enqueue_script('wc-add-to-cart');
    }
}

add_action('wp_ajax_woocommerce_ajax_add_to_cart', 'woocommerce_ajax_add_to_cart');
add_action('wp_ajax_nopriv_woocommerce_ajax_add_to_cart', 'woocommerce_ajax_add_to_cart');

function woocommerce_ajax_add_to_cart() {
    $product_id = apply_filters('woocommerce_add_to_cart_product_id', absint($_POST['product_id']));
    $quantity = empty($_POST['quantity']) ? 1 : wc_stock_amount($_POST['quantity']);
    $passed_validation = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $quantity);
    $product_status = get_post_status($product_id);

    if ($passed_validation && WC()->cart->add_to_cart($product_id, $quantity) && 'publish' === $product_status) {
        do_action('woocommerce_ajax_added_to_cart', $product_id);
        if ('yes' === get_option('woocommerce_cart_redirect_after_add')) {
            wc_add_to_cart_message(array($product_id => $quantity), true);
        }
        WC_AJAX :: get_refreshed_fragments();
    } else {
        $data = array(
            'error' => true,
            'product_url' => apply_filters('woocommerce_cart_redirect_after_error', get_permalink($product_id), $product_id)
        );
        echo wp_send_json($data);
    }
    wp_die();
}

/**
 * Liefert (oder erstellt) den Self-Service-Umbuchungstoken für eine Bestellung.
 */
function custom_events_get_rebooking_token($order_id, $force_refresh = false) {
    $order_id = absint($order_id);
    if (!$order_id) {
        return '';
    }

    if ($force_refresh) {
        delete_post_meta($order_id, '_customer_rebooking_token');
    }

    $token = get_post_meta($order_id, '_customer_rebooking_token', true);

    if (empty($token)) {
        $token = wp_generate_password(32, false, false);
        update_post_meta($order_id, '_customer_rebooking_token', $token);
    }

    return $token;
}

/**
 * Prüft, ob der übergebene Token zu der Bestellung gehört.
 */
function custom_events_validate_rebooking_token($order_id, $token) {
    $order_id = absint($order_id);
    if (!$order_id || empty($token)) {
        return false;
    }

    $stored_token = get_post_meta($order_id, '_customer_rebooking_token', true);
    if (!$stored_token) {
        return false;
    }

    return hash_equals($stored_token, $token);
}

/**
 * Gibt die URL der Umbuchungsseite zurück. Fallback ist /umbuchen/.
 */
function custom_events_get_rebooking_page_url() {
    $page = get_page_by_path('umbuchen');
    if ($page) {
        $url = get_permalink($page->ID);
    } else {
        $url = home_url('/umbuchen/');
    }

    return esc_url_raw(apply_filters('custom_events_rebooking_page_url', $url));
}

/**
 * Baut die komplette Self-Service-Umbuchungs-URL.
 */
function custom_events_get_rebooking_url($order_id) {
    $order_id = absint($order_id);
    if (!$order_id) {
        return '';
    }

    $token = custom_events_get_rebooking_token($order_id);
    if (empty($token)) {
        return '';
    }

    $base_url = custom_events_get_rebooking_page_url();
    $query_args = array(
        'order' => $order_id,
        'token' => $token
    );

    return esc_url_raw(add_query_arg($query_args, $base_url));
}

?>
