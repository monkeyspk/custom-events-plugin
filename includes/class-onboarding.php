<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class Custom_Events_Onboarding {
    public function __construct() {
        add_action('wp_ajax_load_onboarding_content', array($this, 'load_onboarding_content'));
        add_action('wp_ajax_nopriv_load_onboarding_content', array($this, 'load_onboarding_content'));
        add_action('woocommerce_thankyou', array($this, 'enqueue_onboarding_scripts'));
    }

    public function enqueue_onboarding_scripts($order_id) {
        if ( is_order_received_page() ) {
            wp_enqueue_style(
                'my-onboarding-css',
                plugin_dir_url(__FILE__) . '../assets/css/my-onboarding.css',
                array(),
                '1.0.0'
            );
            wp_enqueue_script(
                'my-onboarding-js',
                plugin_dir_url(__FILE__) . '../assets/js/my-onboarding.js',
                array('jquery'),
                '1.0',
                true
            );
            wp_localize_script('my-onboarding-js', 'MyOnboardingAjax', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'orderId' => $order_id,
            ));
            add_action('wp_footer', array($this, 'print_modal_container'));
        }
    }

    public function print_modal_container() {
        ?>
        <div class="my-onboarding-overlay" id="myOnboardingOverlay">
            <div class="my-onboarding-modal" id="myOnboardingModal">
                <span class="my-onboarding-close" id="myOnboardingClose">&times;</span>
                <div id="myOnboardingContent"></div>
            </div>
        </div>
        <?php
    }

    public function load_onboarding_content() {
        $steps_html = '
            <div class="my-onboarding-step" data-step="1">
                <img src="https://berlin.parkourone.com/wp-content/uploads/2025/02/checkout-foto.jpg" alt="ParkourONE Training" style="width: 100%; height: auto; border-radius: 8px; margin-bottom: 20px;">
                <h2>Willkommen bei ParkourONE!</h2>
                <p>Deine Reise beginnt jetzt! Du wirst nicht nur neue Bewegungen lernen, sondern auch deine Umgebung mit anderen Augen sehen. Was für andere ein Hindernis ist, wird für dich zur Chance.</p>
            </div>
            <div class="my-onboarding-step" data-step="2" style="display:none;">
                <img src="https://berlin.parkourone.com/wp-content/uploads/2025/02/checkout-foto.jpg" alt="ParkourONE Community" style="width: 100%; height: auto; border-radius: 8px; margin-bottom: 20px;">
                <h2>Unsere Community</h2>
                <p>ParkourONE ist mehr als reines Training – wir wachsen gemeinsam. Wir achten auf unsere Umgebung, lernen voneinander und unterstützen uns. Jeder entwickelt sich in seinem eigenen Tempo.</p>
            </div>
            <div class="my-onboarding-step" data-step="3" style="display:none;">
                <img src="https://berlin.parkourone.com/wp-content/uploads/2025/02/checkout-foto.jpg" alt="ParkourONE Vorbereitung" style="width: 100%; height: auto; border-radius: 8px; margin-bottom: 20px;">
                <h2>Gut vorbereitet zum Training</h2>
                <p>Bring Wasser mit, trag bequeme Sportkleidung und sei 10 Minuten vor Beginn am Treffpunkt. Wir sind bei jedem Wetter draussen.<br><br>
                <strong>Wir freuen uns auf dich!</strong><br>Dein ParkourONE Team</p>
            </div>
            <div class="my-onboarding-nav">
                <button type="button" class="my-onboarding-prev" style="display:none;">Zurück</button>
                <button type="button" class="my-onboarding-next">Weiter</button>
            </div>
        ';
        wp_send_json_success($steps_html);
    }
}
new Custom_Events_Onboarding();
?>
