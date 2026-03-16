<?php
class Event_Shortcodes {
    private $product_handler;
    private $filter_handler;
    private $api;

    public function __construct() {
        add_shortcode('event_list', array($this, 'render_event_list'));
        add_shortcode('ab_event_coach_image', array($this, 'render_coach_image')); // Neuer Shortcode
        $this->product_handler = new Event_Dynamic_Product_Handler();
        $this->filter_handler = new Event_Advanced_Filter();
        $this->api = new Event_API();
    }

    public function render_event_list($atts) {
        // JavaScript einbinden
        wp_enqueue_script(
            'event-list',
            plugins_url('assets/js/event-list.js', dirname(__FILE__)),
            array('jquery'),
            '1.0',
            true
        );

        // CSS einbinden
        wp_enqueue_style(
            'event-list-style',
            plugins_url('assets/css/event-list.css', dirname(__FILE__)),
            array(),
            '1.0'
        );

        // Daten an JavaScript übergeben
        wp_localize_script('event-list', 'eventListData', array(
            'apiUrl' => rest_url('events/v1/list'),
            'nonce' => wp_create_nonce('event_filter_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'currencySymbol' => get_woocommerce_currency_symbol() // Neue Zeile hinzufügen
        ));

        // Filterformular rendern
        $filter = $this->filter_handler->render_advanced_filter();

        // Event‑Liste Container
        $output = $filter . '<div id="parkourone-event-list" class="parkourone-event-list">';

        // 3 Skeleton-Platzhalter
        for ($i = 0; $i < 3; $i++) {
            $output .= '
                <div class="event-skeleton">
                    <div class="event-skeleton-date skeleton"></div>
                    <div class="event-skeleton-title skeleton"></div>
                    <div class="event-skeleton-meta">
                        <div class="event-skeleton-meta-item skeleton"></div>
                        <div class="event-skeleton-meta-item skeleton"></div>
                        <div class="event-skeleton-meta-item skeleton"></div>
                    </div>
                </div>';
        }

        $output .= '</div>';

        return $output;
    }

    public function render_coach_image($atts) {
        global $ab_current_order;

        if (!$ab_current_order) {
            return '';
        }

        // Durchsuche die Bestellpositionen nach Coach-Bild
        foreach ($ab_current_order->get_items() as $item) {
            $coach_image = $item->get_meta('_event_coach_image');
            if (!empty($coach_image)) {
              return '<img style="width: 100px; height: 100px; border-radius: 50%; margin-right: 25px; object-fit: cover;" src="' . esc_url($coach_image) . '" alt="Coach" />';
            }
        }

        return ''; // Fallback wenn kein Bild gefunden
    }



}
?>
