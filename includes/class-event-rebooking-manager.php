<?php
/**
 * Event Rebooking Manager
 * Ermöglicht Admins, Kunden auf andere Events umzubuchen
 *
 * @package Custom_Events_Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}
class Event_Rebooking_Manager {
    private $per_page = 20;
    private $customer_allowed_statuses = array('probetraining', 'workshop');

    public function __construct() {
        // Menü-Registrierung erfolgt über Event_Admin_Page (kombinierte Seite)

        // AJAX-Aktionen registrieren
        add_action('wp_ajax_search_event_orders', array($this, 'ajax_search_event_orders'));
        add_action('wp_ajax_get_available_events', array($this, 'ajax_get_available_events'));
        add_action('wp_ajax_rebook_order', array($this, 'ajax_rebook_order'));

        // Frontend Shortcode & AJAX für Self-Service-Umbuchungen
        add_shortcode('event_customer_rebooking', array($this, 'render_customer_rebooking_form'));
        add_action('wp_ajax_event_customer_rebooking_init', array($this, 'ajax_customer_rebooking_init'));
        add_action('wp_ajax_nopriv_event_customer_rebooking_init', array($this, 'ajax_customer_rebooking_init'));
        add_action('wp_ajax_event_customer_rebook_order', array($this, 'ajax_customer_rebook_order'));
        add_action('wp_ajax_nopriv_event_customer_rebook_order', array($this, 'ajax_customer_rebook_order'));
    }

    /**
     * AJAX-Handler für die Umbuchung einer Bestellung
     */
    public function ajax_rebook_order() {
        check_ajax_referer('wp_rest', '_wpnonce');

        $old_order_id     = isset($_POST['old_order_id']) ? intval($_POST['old_order_id']) : 0;
        $new_product_id   = isset($_POST['new_product_id']) ? intval($_POST['new_product_id']) : 0;
         $delete_old_order = isset($_POST['delete_old_order']) && $_POST['delete_old_order'] === 'true';

         if (!$old_order_id || !$new_product_id) {
             wp_send_json_error('Ungültige Parameter für die Umbuchung.');
         }

        $mode = isset($_POST['transfer_mode']) && $_POST['transfer_mode'] === 'transfer' ? 'transfer' : 'rebook';

        $result = $this->perform_rebooking($old_order_id, $new_product_id, array(
            'delete_old_order' => $delete_old_order,
            'context'          => 'admin',
            'mode'             => $mode,
        ));

         if (is_wp_error($result)) {
             wp_send_json_error($result->get_error_message());
         }

        wp_send_json_success($result);
    }

    /**
     * Zentrale Rebooking-Logik für Admin- und Kunden-Umbuchungen.
     */
    private function perform_rebooking($old_order_id, $new_product_id, $args = array()) {
        $args = wp_parse_args($args, array(
            'delete_old_order' => false,
            'context'          => 'admin',
            'mode'             => 'rebook',
        ));

        $mode = ($args['mode'] === 'transfer') ? 'transfer' : 'rebook';

        try {
            $old_order = wc_get_order($old_order_id);
            if (!$old_order) {
                throw new Exception('Alte Bestellung nicht gefunden.');
            }

            $new_product = wc_get_product($new_product_id);
            if (!$new_product) {
                throw new Exception('Neues Produkt nicht gefunden.');
            }

            $this->log_error('Starte Umbuchung', [
                'old_order_id'   => $old_order_id,
                'new_product_id' => $new_product_id,
                'context'        => $args['context'],
            ]);

            $old_participant_data = [];
            $old_event_meta       = [];

            foreach ($old_order->get_items() as $item) {
                $meta_data = $item->get_meta_data();
                foreach ($meta_data as $meta) {
                    $meta_key = $meta->key;
                    if (strpos($meta_key, '_event_') === 0) {
                        $old_event_meta[$meta_key] = $meta->value;
                    }
                }

                if ($item->get_meta('_event_participant_data')) {
                    $old_participant_data = $item->get_meta('_event_participant_data');
                }
            }

            $this->log_error('Extrahierte Event-Metadaten', $old_event_meta);

            if (empty($old_participant_data)) {
                throw new Exception('Keine Teilnehmerdaten in der alten Bestellung gefunden.');
            }

            $customer_note = ($mode === 'transfer')
                ? 'Übertragen aus Bestellung #' . $old_order_id
                : 'Umgebucht von Bestellung #' . $old_order_id;

            $new_order = wc_create_order([
                'status'        => 'pending',
                'customer_id'   => $old_order->get_customer_id(),
                'customer_note' => $customer_note,
                'created_via'   => ($args['context'] === 'customer') ? 'customer_rebooking' : 'admin_rebooking'
            ]);

            $address_fields = ['first_name', 'last_name', 'company', 'address_1',
                               'address_2', 'city', 'postcode', 'country', 'state',
                               'email', 'phone'];
            $billing_address = [];

            foreach ($address_fields as $field) {
                $getter = "get_billing_{$field}";
                if (method_exists($old_order, $getter)) {
                    $billing_address[$field] = $old_order->$getter();
                }
            }

            $new_order->set_address($billing_address, 'billing');
            $new_order->set_address($old_order->get_address('shipping'), 'shipping');

            $old_order_meta = get_post_meta($old_order_id);
            $excluded_order_meta = [
                '_edit_lock', '_edit_last', '_order_key',
                '_event_id', '_event_product_id', '_event_date', '_event_title',
                '_event_title_clean', '_event_time', '_event_venue', '_event_venue_lat',
                '_event_venue_lng', '_event_coach', '_event_coach_email', '_event_coach_phone',
                '_event_coach_image', '_event_description', '_event_whatsapp_link',
                // E-Mail-Marker NICHT kopieren - sonst werden keine Bestätigungs-E-Mails gesendet
                '_ab_email_sent_probetraining',
                '_ab_email_sent_vertragverschickt',
                '_ab_email_sent_schuelerin',
                '_ab_email_sent_warteliste',
                '_ab_email_sent_abgelehnt',
                '_ab_email_sent_gekuendigt',
                '_ab_email_sent_keinerueckmeldung',
                '_ab_email_sent_nichterschienen',
                '_ab_email_sent_kdginitiiert',
                '_ab_skip_probetraining_email',
                '_ab_silent_update',
            ];
            foreach ($old_order_meta as $meta_key => $meta_values) {
                if (in_array($meta_key, $excluded_order_meta) || strpos($meta_key, '_event_') === 0) {
                    continue;
                }

                foreach ($meta_values as $meta_value) {
                    update_post_meta($new_order->get_id(), $meta_key, maybe_unserialize($meta_value));
                }
            }

            $event_title_clean = get_post_meta($new_event_id, '_event_title_clean', true);
            if (empty($event_title_clean)) {
                $event_title_clean = $event_title;
            }
            update_post_meta($new_order->get_id(), '_event_title_clean', $event_title_clean);
            update_post_meta($new_order->get_id(), '_event_id', $new_event_id);
            update_post_meta($new_order->get_id(), '_event_description', $event_description);

            $item_id = $new_order->add_product(
                $new_product,
                count($old_participant_data)
            );

            if ($new_product->managing_stock()) {
                $new_stock = $new_product->get_stock_quantity();
                $this->log_error('Bestandsstatus nach Umbuchung', [
                    'product_id'         => $new_product_id,
                    'new_stock'          => $new_stock,
                    'participants_count' => count($old_participant_data)
                ]);
            }

            $item = $new_order->get_item($item_id);

            $new_event_id        = get_post_meta($new_product_id, '_event_id', true);
            $event_date          = get_post_meta($new_product_id, '_event_date', true);
            $event_title         = get_the_title($new_event_id);
            $event_venue         = get_post_meta($new_event_id, '_event_venue', true);
            $event_start         = get_post_meta($new_event_id, '_event_start_time', true);
            $event_end           = get_post_meta($new_event_id, '_event_end_time', true);
            $event_time          = ($event_start && $event_end) ? $event_start . ' - ' . $event_end : '';
            $event_coach         = get_post_meta($new_event_id, '_event_headcoach', true);
            $event_coach_email   = get_post_meta($new_event_id, '_event_headcoach_email', true);
            $event_coach_phone   = get_post_meta($new_event_id, '_event_headcoach_phone', true);
            $event_coach_img     = get_post_meta($new_event_id, '_event_headcoach_image_url', true);
            $event_description   = get_post_meta($new_event_id, '_event_description', true);
            $event_whatsapp_link = get_post_meta($new_event_id, '_event_whatsapp_link', true);
            $event_course_id     = get_post_meta($new_event_id, '_event_course_id', true);

            $event_dates = get_post_meta($new_event_id, '_event_dates', true);
            $venue_data  = [
                'venue'     => $event_venue,
                'venue_lat' => '',
                'venue_lng' => ''
            ];

            if (!empty($event_dates) && is_array($event_dates)) {
                foreach ($event_dates as $date_info) {
                    if (trim($date_info['date']) === trim($event_date)) {
                        $venue_data = [
                            'venue'     => isset($date_info['venue']) ? $date_info['venue'] : $event_venue,
                            'venue_lat' => isset($date_info['venue_lat']) ? $date_info['venue_lat'] : '',
                            'venue_lng' => isset($date_info['venue_lng']) ? $date_info['venue_lng'] : ''
                        ];
                        break;
                    }
                }
            }

            if ($item) {
                $item->add_meta_data('_event_participant_data', $old_participant_data);
                $item->add_meta_data('_event_id', $new_event_id);
                $item->add_meta_data('_event_product_id', $new_product_id);
                $item->add_meta_data('_event_date', $event_date);
                $item->add_meta_data('_event_title', $event_title);
                $item->add_meta_data('_event_time', $event_time);
                $item->add_meta_data('_event_venue', $venue_data['venue']);
                $item->add_meta_data('_event_venue_lat', $venue_data['venue_lat']);
                $item->add_meta_data('_event_venue_lng', $venue_data['venue_lng']);
                $item->add_meta_data('_event_coach', $event_coach);
                $item->add_meta_data('_event_coach_email', $event_coach_email);
                $item->add_meta_data('_event_coach_phone', $event_coach_phone);

                if ($event_coach_img) {
                    $item->add_meta_data('_event_coach_image', $event_coach_img);
                }

                $item->add_meta_data('_event_description', $event_description ? $event_description : '');

                // NEU: course_id für robuste Vertragstyp-Zuordnung
                if ($event_course_id) {
                    $item->add_meta_data('_event_course_id', $event_course_id);
                }

                if ($event_whatsapp_link) {
                    $item->add_meta_data('_event_whatsapp_link', $event_whatsapp_link);
                }

                foreach ($old_event_meta as $meta_key => $meta_value) {
                    if (!in_array($meta_key, [
                        '_event_participant_data', '_event_id', '_event_product_id',
                        '_event_date', '_event_title', '_event_time', '_event_venue',
                        '_event_venue_lat', '_event_venue_lng', '_event_coach',
                        '_event_coach_email', '_event_coach_phone', '_event_coach_image',
                        '_event_description', '_event_whatsapp_link', '_event_course_id'
                    ])) {
                        $item->add_meta_data($meta_key, $meta_value);
                    }
                }

                $item->add_meta_data('_rebooked_from_order', $old_order_id);
                $item->add_meta_data('_rebooked_date', current_time('mysql'));
                $item->add_meta_data('_rebooking_action', $mode);

                if ('customer' === $args['context']) {
                    $item->add_meta_data('_rebooked_via', 'customer');
                }

                $item->save();
            }

            $action_status_note = ($mode === 'transfer')
                ? 'Übertragung aus Bestellung #' . $old_order_id
                : 'Umbuchung von Bestellung #' . $old_order_id;

            $new_order->calculate_totals();
            $new_order->update_status('probetraining', $action_status_note);

            $this->log_error('Neue Bestellung erstellt', [
                'new_order_id' => $new_order->get_id(),
                'status'       => $new_order->get_status()
            ]);

            $action_label = ($mode === 'transfer') ? __('Übertragung', 'your-text-domain') : __('Umbuchung', 'your-text-domain');

            // Alte Bestellung immer löschen und Notiz in neuer Bestellung hinterlassen
            $rebooking_note = sprintf(
                '%s durchgeführt. Ursprüngliche Bestellung #%s wurde gelöscht.',
                $action_label,
                $old_order_id
            );
            $new_order->add_order_note($rebooking_note);
            update_post_meta($new_order->get_id(), '_rebooked_from_order_id', $old_order_id);
            
            $old_order->delete(true);
            $message = sprintf('%s abgeschlossen. Alte Bestellung #%s wurde gelöscht.', $action_label, $old_order_id);

            if ('customer' === $args['context']) {
                $message = __('Danke! Wir haben deine Umbuchung durchgeführt.', 'your-text-domain');
            }

            return array(
                'new_order_id' => $new_order->get_id(),
                'message'      => $message,
            );
        } catch (Exception $e) {
            $error_message = $this->log_error('Fehler bei der Umbuchung', [
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
                'old_order_id'   => $old_order_id,
                'new_product_id' => $new_product_id,
                'context'        => $args['context'],
            ]);

            return new WP_Error('rebooking_failed', $error_message);
        }
    }

 
    /**
     * Admin-Menü für Umbuchungen hinzufügen
     */
    public function add_rebooking_menu() {
        add_submenu_page(
            'parkourone',
            'Umbuchungen',
            'Umbuchungen',
            'manage_options',
            'event-rebooking',
            array($this, 'render_rebooking_page')
        );
    }

    /**
     * Die Haupt-Admin-Seite für Umbuchungen rendern
     */
    public function render_rebooking_page() {
        // Scripts und Styles laden
        $this->enqueue_admin_scripts();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Event Umbuchungen', 'your-text-domain'); ?></h1>

            <!-- Suchformular -->
            <div class="rebooking-search-form">
                <h2><?php echo esc_html__('Buchungen suchen', 'your-text-domain'); ?></h2>
                <div class="search-container">
                    <input type="text" id="customer-search" placeholder="Kunden-Name, Email oder Bestellnummer eingeben...">
                    <button id="search-orders-btn" class="button button-primary"><?php echo esc_html__('Suchen', 'your-text-domain'); ?></button>
                </div>
            </div>

            <!-- Ergebnistabelle -->
            <div class="rebooking-search-results" style="margin-top: 20px;">
                <table class="wp-list-table widefat fixed striped" id="rebooking-orders-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Bestellung', 'your-text-domain'); ?></th>
                            <th><?php echo esc_html__('Kunde', 'your-text-domain'); ?></th>
                            <th><?php echo esc_html__('Event', 'your-text-domain'); ?></th>
                            <th><?php echo esc_html__('Datum', 'your-text-domain'); ?></th>
                            <th><?php echo esc_html__('Teilnehmer', 'your-text-domain'); ?></th>
                            <th><?php echo esc_html__('Aktionen', 'your-text-domain'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="rebooking-orders-body">
                        <!-- Hier werden die Suchergebnisse eingetragen -->
                        <tr>
                            <td colspan="6"><?php echo esc_html__('Suche nach Kunden, um Umbuchungen zu verwalten.', 'your-text-domain'); ?></td>
                        </tr>
                    </tbody>
                </table>
                <div id="rebooking-orders-pagination" class="tablenav bottom">
                    <!-- Pagination wird hier angezeigt -->
                </div>
            </div>

            <!-- Umbuchungs-Modal -->
            <div id="rebooking-modal" style="display: none;">
                <div class="rebooking-modal-backdrop"></div>
                <div class="rebooking-modal-content">
                    <div class="rebooking-modal-header">
                        <h2><?php echo esc_html__('Umbuchung durchführen', 'your-text-domain'); ?></h2>
                        <span class="rebooking-modal-close dashicons dashicons-no-alt"></span>
                    </div>
                    <div class="rebooking-modal-body">
                        <div class="rebooking-order-info">
                            <h3><?php echo esc_html__('Aktuelle Buchung', 'your-text-domain'); ?></h3>
                            <p><strong><?php echo esc_html__('Bestellnummer:', 'your-text-domain'); ?></strong> <span id="current-order-id"></span></p>
                            <p><strong><?php echo esc_html__('Kunde:', 'your-text-domain'); ?></strong> <span id="current-customer"></span></p>
                            <p><strong><?php echo esc_html__('Aktuelles Event:', 'your-text-domain'); ?></strong> <span id="current-event"></span></p>
                            <p><strong><?php echo esc_html__('Aktuelles Datum:', 'your-text-domain'); ?></strong> <span id="current-date"></span></p>
                        </div>

                        <hr>

                        <div class="rebooking-new-event">
                            <h3><?php echo esc_html__('Aktion & Zielklasse wählen', 'your-text-domain'); ?></h3>
                            <div class="form-field">
                                <label for="rebooking-action"><?php echo esc_html__('Aktion', 'your-text-domain'); ?></label>
                                <select id="rebooking-action">
                                    <option value="rebook"><?php echo esc_html__('Umbuchung innerhalb der aktuellen Klasse', 'your-text-domain'); ?></option>
                                    <option value="transfer"><?php echo esc_html__('In andere Klasse übertragen', 'your-text-domain'); ?></option>
                                </select>
                                <p class="description"><?php echo esc_html__('Nutze "Übertragen", wenn du den Probetrainingseintrag bewusst einer anderen Klasse zuordnen möchtest.', 'your-text-domain'); ?></p>
                            </div>
                            <div class="form-field">
                                <label for="new-event-select"><?php echo esc_html__('Event:', 'your-text-domain'); ?></label>
                                <select id="new-event-select">
                                    <option value=""><?php echo esc_html__('Bitte Event wählen...', 'your-text-domain'); ?></option>
                                </select>
                            </div>
                            <div class="form-field">
                                <label for="new-event-date"><?php echo esc_html__('Klasse:', 'your-text-domain'); ?></label>
                                <select id="new-event-date">
                                    <option value=""><?php echo esc_html__('Bitte erst Event wählen...', 'your-text-domain'); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="form-field info-field">
                            <p class="description"><strong>Hinweis:</strong> Die alte Bestellung wird automatisch gelöscht. Die neue Bestellung enthält eine Notiz mit der ursprünglichen Bestellnummer.</p>
                        </div>
                    </div>
                    <div class="rebooking-modal-footer">
                        <button class="button button-secondary rebooking-modal-cancel"><?php echo esc_html__('Abbrechen', 'your-text-domain'); ?></button>
                        <button class="button button-primary" id="process-rebooking-btn"><?php echo esc_html__('Umbuchung durchführen', 'your-text-domain'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Admin Scripts und Styles einbinden
     */
     private function enqueue_admin_scripts() {
         // CSS-Datei einbinden (wenn extern angelegt)
         wp_enqueue_style('rebooking-admin-styles', plugin_dir_url(dirname(__FILE__)) . 'assets/css/rebooking-admin.css', [], '1.0.0');

         // JS-Datei einbinden (wenn extern angelegt)
         wp_enqueue_script('rebooking-admin-script', plugin_dir_url(dirname(__FILE__)) . 'assets/js/rebooking-admin.js', ['jquery'], '1.0.0', true);

         // Lokalisieren des Scripts mit notwendigen Übersetzungen und Nonce
        wp_localize_script('rebooking-admin-script', 'rebookingAdminL10n', [
            'pleaseSelectEvent' => __('Bitte Event wählen...', 'your-text-domain'),
            'pleaseSelectDate' => __('Bitte Klasse wählen...', 'your-text-domain'),
            'pleaseSelectEventFirst' => __('Bitte erst Event wählen...', 'your-text-domain'),
            'noDatesAvailable' => __('Keine Termine verfügbar', 'your-text-domain'),
        ]);

         // Für REST API Nonce
         wp_localize_script('rebooking-admin-script', 'wpApiSettings', [
             'nonce' => wp_create_nonce('wp_rest')
         ]);
     }

    /**
     * AJAX-Handler für die Suche nach Bestellungen
     */
    public function ajax_search_event_orders() {
        check_ajax_referer('wp_rest', '_wpnonce');

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;

        // Alle WooCommerce-Status abrufen (sowohl Standard als auch benutzerdefinierte)
        $all_statuses = array_keys(wc_get_order_statuses());

        // Bestellungen suchen mit allen Status
        $args = array(
            'limit' => $this->per_page,
            'paged' => $page,
            'return' => 'ids',
            'status' => $all_statuses
        );

        // Suchfilter anwenden
        if (!empty($search)) {
            // Prüfen, ob es eine Bestellnummer ist
            if (is_numeric($search)) {
                $args['order_id'] = $search;
            } else {
                // Nach Kunden-Namen oder E-Mail suchen
                if (strpos($search, '@') !== false) {
                    // E-Mail-Suche
                    $args['billing_email'] = $search;
                } else {
                    // Namen-Suche (Vor- oder Nachname)
                    global $wpdb;

                    // IDs von Bestellungen finden, die den Suchbegriff enthalten
                    $order_ids = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT post_id
                            FROM {$wpdb->postmeta}
                            WHERE (meta_key = '_billing_first_name' OR meta_key = '_billing_last_name')
                            AND meta_value LIKE %s",
                            '%' . $wpdb->esc_like($search) . '%'
                        )
                    );

                    if (!empty($order_ids)) {
                        $args['post__in'] = $order_ids;
                    } else {
                        // Auch in Teilnehmerdaten suchen
                        $participant_order_ids = $wpdb->get_col(
                            $wpdb->prepare(
                                "SELECT post_id
                                FROM {$wpdb->postmeta}
                                WHERE meta_key = '_event_participant_data'
                                AND meta_value LIKE %s",
                                '%' . $wpdb->esc_like($search) . '%'
                            )
                        );

                        if (!empty($participant_order_ids)) {
                            $args['post__in'] = $participant_order_ids;
                        } else {
                            // Keine Ergebnisse gefunden
                            wp_send_json_success(array(
                                'orders' => array(),
                                'total_pages' => 0
                            ));
                            return;
                        }
                    }
                }
            }
        }

        // Nur Bestellungen mit Event-Produkten einbeziehen
        $args['meta_query'] = array(
            array(
                'key' => '_event_title',
                'compare' => 'EXISTS'
            )
        );

        // Bestellungen abrufen
        $query = new WC_Order_Query($args);
        $order_ids = $query->get_orders();

        // Gesamtanzahl der Bestellungen für Paginierung
        $args_count = $args;
        unset($args_count['limit']);
        unset($args_count['paged']);
        $args_count['return'] = 'ids';

        $count_query = new WC_Order_Query($args_count);
        $total_orders = count($count_query->get_orders());
        $total_pages = ceil($total_orders / $this->per_page);

        // Bestelldaten für die Anzeige aufbereiten
        $orders_data = array();

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);

            foreach ($order->get_items() as $item) {
                // Prüfen, ob es ein Event-Produkt ist
                $event_title = $item->get_meta('_event_title');

                if ($event_title) {
                    $event_date = $item->get_meta('_event_date');
                    $event_id = $item->get_meta('_event_id');
                    $participant_data = $item->get_meta('_event_participant_data');

                    // Teilnehmerdaten formatieren
                    $participants = '';
                    $participants_count = 0;

                    if (!empty($participant_data) && is_array($participant_data)) {
                        $participants_count = count($participant_data);

                        foreach ($participant_data as $participant) {
                            if (isset($participant['vorname'], $participant['name'])) {
                                $participants .= $participant['vorname'] . ' ' . $participant['name'];

                                if (isset($participant['geburtsdatum']) && !empty($participant['geburtsdatum'])) {
                                    $participants .= ' (' . $participant['geburtsdatum'] . ')';
                                }

                                $participants .= ', ';
                            }
                        }

                        $participants = rtrim($participants, ', ');
                    }

                    // Kundendaten
                    $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                    $customer_email = $order->get_billing_email();

                    $orders_data[] = array(
                        'id' => $order_id,
                        'customer' => $customer_name . ' (' . $customer_email . ')',
                        'customer_name' => $customer_name,
                        'customer_email' => $customer_email,
                        'event_id' => $event_id,
                        'event_product_id' => $item->get_meta('_event_product_id'),
                        'event_title' => $event_title,
                        'event_date' => $event_date,
                        'participants' => $participants,
                        'participants_count' => $participants_count,
                        'participant_data' => $participant_data,
                        'order_data' => array(
                            'billing_first_name' => $order->get_billing_first_name(),
                            'billing_last_name' => $order->get_billing_last_name(),
                            'billing_company' => $order->get_billing_company(),
                            'billing_address_1' => $order->get_billing_address_1(),
                            'billing_address_2' => $order->get_billing_address_2(),
                            'billing_city' => $order->get_billing_city(),
                            'billing_postcode' => $order->get_billing_postcode(),
                            'billing_country' => $order->get_billing_country(),
                            'billing_email' => $order->get_billing_email(),
                            'billing_phone' => $order->get_billing_phone()
                        )
                    );
                }
            }
        }

        wp_send_json_success(array(
            'orders' => $orders_data,
            'total_pages' => $total_pages
        ));
    }

    /**
     * AJAX-Handler zum Laden verfügbarer Events
     */
    public function ajax_get_available_events() {
        check_ajax_referer('wp_rest', '_wpnonce');

        $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;

        if ($event_id > 0) {
            $products_data = $this->get_event_products($event_id);

            wp_send_json_success(array(
                'event_id' => $event_id,
                'products' => $products_data
            ));
        } else {
            // Alle Events laden
            $args = array(
                'post_type' => 'event',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC'
            );

            $events = get_posts($args);
            $events_data = array();

            foreach ($events as $event) {
                $events_data[] = array(
                    'id' => $event->ID,
                    'title' => $event->post_title
                );
            }

            wp_send_json_success($events_data);
        }
    }

    /**
     * Shortcode-Ausgabe für die Kunden-Umbuchung.
     */
    public function render_customer_rebooking_form($atts = array()) {
        $order_id = isset($_GET['order']) ? intval($_GET['order']) : 0;
        $token    = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

        if (!$order_id || empty($token)) {
            return '<div class="customer-rebooking-message error">' . esc_html__('Dieser Umbuchungslink ist ungültig.', 'your-text-domain') . '</div>';
        }

        $context_check = $this->get_customer_order_context($order_id, $token);
        if (is_wp_error($context_check)) {
            return '<div class="customer-rebooking-message error">' . esc_html($context_check->get_error_message()) . '</div>';
        }

        $this->enqueue_customer_scripts($order_id, $token);

        ob_start();
        ?>
        <div id="customer-rebooking-root" class="customer-rebooking-root" data-order-id="<?php echo esc_attr($order_id); ?>">
            <div class="customer-rebooking-loading"><?php echo esc_html__('Wir laden deine Buchung...', 'your-text-domain'); ?></div>
            <div class="customer-rebooking-message" style="display:none;"></div>
            <div class="customer-rebooking-content" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Kundenseitiges AJAX zum Laden der Bestell- und Termin-Daten.
     */
    public function ajax_customer_rebooking_init() {
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $token    = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        $context = $this->get_customer_order_context($order_id, $token);
        if (is_wp_error($context)) {
            wp_send_json_error($context->get_error_message());
        }

        $order = $context['order'];
        $item  = $context['item'];

        $participant_data   = $context['participants'];
        $participants_list  = $this->format_participants($participant_data);
        $participants_count = is_array($participant_data) ? count($participant_data) : 0;

        $current_product_id = $item->get_meta('_event_product_id');
        if (!$current_product_id) {
            $current_product_id = $item->get_product_id();
        }

        $event_id = $context['event_id'];

        $available_products = $this->get_event_products($event_id, array(
            'only_upcoming' => true,
        ));

        $options = array();
        foreach ($available_products as $product) {
            $options[] = array(
                'id'          => $product['id'],
                'label'       => sprintf('%s (%s)', $product['date'], $product['availability']),
                'is_current'  => ($product['id'] == $current_product_id),
                'is_available'=> !empty($product['is_available']),
                'timestamp'   => isset($product['timestamp']) ? $product['timestamp'] : null,
            );
        }

        wp_send_json_success(array(
            'order' => array(
                'id'                 => $order->get_id(),
                'number'             => $order->get_order_number(),
                'status'             => $order->get_status(),
                'event_title'        => sanitize_text_field($item->get_meta('_event_title')),
                'event_date'         => sanitize_text_field($item->get_meta('_event_date')),
                'event_time'         => sanitize_text_field($item->get_meta('_event_time')),
                'event_venue'        => sanitize_text_field($item->get_meta('_event_venue')),
                'participants'       => $participants_list,
                'participants_count' => $participants_count,
            ),
            'options' => $options,
        ));
    }

    /**
     * Kundenseitiges AJAX zum Ausführen der Umbuchung.
     */
    public function ajax_customer_rebook_order() {
        $order_id       = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $token          = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $new_product_id = isset($_POST['new_product_id']) ? intval($_POST['new_product_id']) : 0;

        if (!$new_product_id) {
            wp_send_json_error(__('Bitte wähle einen neuen Termin aus.', 'your-text-domain'));
        }

        $context = $this->get_customer_order_context($order_id, $token);
        if (is_wp_error($context)) {
            wp_send_json_error($context->get_error_message());
        }

        $item               = $context['item'];
        $event_id           = $context['event_id'];
        $current_product_id = $item->get_meta('_event_product_id');
        if (!$current_product_id) {
            $current_product_id = $item->get_product_id();
        }
        $participant_data   = $context['participants'];
        $participants_count = is_array($participant_data) ? count($participant_data) : 0;

        if ($new_product_id === intval($current_product_id)) {
            wp_send_json_error(__('Du bist bereits für diesen Termin angemeldet.', 'your-text-domain'));
        }

        if (!$this->product_belongs_to_event($new_product_id, $event_id)) {
            wp_send_json_error(__('Der ausgewählte Termin gehört nicht zu deiner Klasse.', 'your-text-domain'));
        }

        $new_product_timestamp = $this->convert_event_date_to_timestamp(get_post_meta($new_product_id, '_event_date', true));
        if ($new_product_timestamp && $new_product_timestamp < current_time('timestamp')) {
            wp_send_json_error(__('Dieser Termin liegt in der Vergangenheit.', 'your-text-domain'));
        }

        $new_product = wc_get_product($new_product_id);
        if (!$new_product) {
            wp_send_json_error(__('Der ausgewählte Termin ist nicht mehr verfügbar.', 'your-text-domain'));
        }

        if ($new_product->managing_stock()) {
            $stock = intval($new_product->get_stock_quantity());
            if ($participants_count > $stock) {
                wp_send_json_error(__('Für diesen Termin sind nicht mehr genug Plätze frei.', 'your-text-domain'));
            }
        }

        $result = $this->perform_rebooking($order_id, $new_product_id, array(
            'delete_old_order' => false,
            'context'          => 'customer',
        ));

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        if (function_exists('custom_events_get_rebooking_token')) {
            custom_events_get_rebooking_token($result['new_order_id'], true);
        }

        wp_send_json_success(array(
            'new_order_id'  => $result['new_order_id'],
            'message'       => $result['message'],
            'rebooking_url' => '',
        ));
    }

    private function enqueue_customer_scripts($order_id, $token) {
        wp_enqueue_style(
            'rebooking-customer-styles',
            plugin_dir_url(dirname(__FILE__)) . 'assets/css/rebooking-customer.css',
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'rebooking-customer-script',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/rebooking-customer.js',
            array('jquery'),
            '1.0.0',
            true
        );

        wp_localize_script('rebooking-customer-script', 'customerRebookingData', array(
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'orderId'      => $order_id,
            'token'        => $token,
            'maxSlots'     => 10,
            'loadMoreStep' => 6,
            'i18n'         => array(
                'heroEyebrow'      => __('Du kannst nicht teilnehmen?', 'your-text-domain'),
                'heroTitle'        => __('Buche dir einen neuen Termin', 'your-text-domain'),
                'heroSubline'      => __('Prüfe dein aktuelles Probetraining und such dir unten einen Ersatztermin aus.', 'your-text-domain'),
                'heroNote'         => __('Dein aktueller Termin steht oben. Darunter findest du die nächsten verfügbaren Sessions.', 'your-text-domain'),
                'currentBooking'   => __('Dein aktuelles Probetraining', 'your-text-domain'),
                'selectLabel'    => __('Neue Session auswählen', 'your-text-domain'),
                'selectSubtitle' => __('Nur Termine deiner Klasse stehen zur Verfügung.', 'your-text-domain'),
                'selectError'    => __('Bitte wähle einen Termin aus.', 'your-text-domain'),
                'submitLabel'    => __('Umbuchung durchführen', 'your-text-domain'),
                'loading'        => __('Daten werden geladen ...', 'your-text-domain'),
                'submitting'     => __('Umbuchung läuft ...', 'your-text-domain'),
                'successMessage' => __('Danke! Wir haben deine Umbuchung durchgeführt.', 'your-text-domain'),
                'genericError'     => __('Es ist ein Fehler aufgetreten. Bitte versuche es erneut oder kontaktiere uns.', 'your-text-domain'),
                'fullLabel'        => __('ausgebucht', 'your-text-domain'),
                'recommendedLabel' => __('Empfohlen', 'your-text-domain'),
                'newLinkLabel'   => __('Weitere Umbuchung starten', 'your-text-domain'),
                'noSlots'        => __('Aktuell keine Termine verfügbar.', 'your-text-domain'),
                'loadMore'       => __('Mehr Termine anzeigen', 'your-text-domain'),
            ),
        ));
    }

    private function get_customer_order_context($order_id, $token) {
        if (!$order_id || empty($token)) {
            return new WP_Error('invalid_request', __('Dieser Umbuchungslink ist ungültig.', 'your-text-domain'));
        }

        if (!function_exists('custom_events_validate_rebooking_token') || !custom_events_validate_rebooking_token($order_id, $token)) {
            return new WP_Error('invalid_token', __('Dieser Umbuchungslink ist abgelaufen oder wurde bereits genutzt.', 'your-text-domain'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', __('Bestellung wurde nicht gefunden.', 'your-text-domain'));
        }

        if (!empty($this->customer_allowed_statuses) && !$order->has_status($this->customer_allowed_statuses)) {
            return new WP_Error('status_not_allowed', __('Für diese Bestellung ist aktuell keine Umbuchung möglich.', 'your-text-domain'));
        }

        $event_item = $this->get_event_item_from_order($order);
        if (!$event_item) {
            return new WP_Error('missing_event_item', __('Zu dieser Bestellung liegen keine Event-Informationen vor.', 'your-text-domain'));
        }

        $event_id = $this->resolve_event_id_from_item($event_item, $order);
        if (!$event_id) {
            return new WP_Error('missing_event_item', __('Zu dieser Bestellung liegen keine Event-Informationen vor.', 'your-text-domain'));
        }

        $event_date = $event_item->get_meta('_event_date');
        if (empty($event_date)) {
            $event_date = $order->get_meta('_event_date');
        }
        $event_time = $event_item->get_meta('_event_time');
        $event_timestamp = $this->convert_event_datetime_to_timestamp($event_date, $event_time);

        $now = new \DateTime('now', function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('Europe/Berlin'));
        $now_timestamp = $now->getTimestamp();
        if ($event_timestamp && $now_timestamp >= ($event_timestamp - DAY_IN_SECONDS)) {
            return new WP_Error('rebooking_window_closed', __('Umbuchungen sind nur bis 24 Stunden vor dem Event möglich.', 'your-text-domain'));
        }

        $participants = $event_item->get_meta('_event_participant_data');

        return array(
            'order'        => $order,
            'item'         => $event_item,
            'participants' => $participants,
            'event_id'     => $event_id,
            'event_timestamp' => $event_timestamp,
        );
    }

    private function get_event_item_from_order($order) {
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_event_id') || $item->get_meta('_event_product_id') || $item->get_meta('_event_title')) {
                return $item;
            }
        }
        return null;
    }

    private function resolve_event_id_from_item($item, $order) {
        $event_id = intval($item->get_meta('_event_id'));
        if ($event_id) {
            return $event_id;
        }

        $event_product_id = intval($item->get_meta('_event_product_id'));
        if (!$event_product_id) {
            $event_product_id = $item->get_product_id();
        }

        if ($event_product_id) {
            $product_event_id = intval(get_post_meta($event_product_id, '_event_id', true));
            if ($product_event_id) {
                return $product_event_id;
            }
        }

        $order_event_id = intval($order->get_meta('_event_id'));
        if ($order_event_id) {
            return $order_event_id;
        }

        return 0;
    }

    private function format_participants($participant_data) {
        if (empty($participant_data) || !is_array($participant_data)) {
            return '';
        }

        $formatted = array();
        foreach ($participant_data as $participant) {
            $name = '';
            if (!empty($participant['vorname'])) {
                $name .= $participant['vorname'] . ' ';
            }
            if (!empty($participant['name'])) {
                $name .= $participant['name'];
            }
            $name = trim($name);
            if (empty($name)) {
                continue;
            }

            if (!empty($participant['geburtsdatum'])) {
                $name .= ' (' . $participant['geburtsdatum'] . ')';
            }

            $formatted[] = sanitize_text_field($name);
        }

        return implode(', ', $formatted);
    }

    private function get_event_products($event_id, $args = array()) {
        $args = wp_parse_args($args, array(
            'only_upcoming' => false,
        ));

        $query_args = array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'   => '_event_id',
                    'value' => $event_id,
                ),
            ),
        );

        $products      = get_posts($query_args);
        $products_data  = array();
        $current_time   = current_time('timestamp');

        foreach ($products as $product) {
            $product_id = $product->ID;
            $product_obj = wc_get_product($product_id);

            if (!$product_obj) {
                continue;
            }

            $date      = get_post_meta($product_id, '_event_date', true);
            $timestamp = $this->convert_event_date_to_timestamp($date);

            if ($args['only_upcoming'] && $timestamp && $timestamp < $current_time) {
                continue;
            }

            $availability_label = __('Unbegrenzt', 'your-text-domain');
            $is_available       = true;

            if ($product_obj->managing_stock()) {
                $stock = intval($product_obj->get_stock_quantity());
                if ($stock <= 0) {
                    $availability_label = __('Ausverkauft', 'your-text-domain');
                    $is_available = false;
                } else {
                    /* translators: %d = number of remaining seats */
                    $availability_label = sprintf(_n('%d Platz frei', '%d Plätze frei', $stock, 'your-text-domain'), $stock);
                }
            }

            $products_data[] = array(
                'id'            => $product_id,
                'date'          => $date,
                'availability'  => $availability_label,
                'is_available'  => $is_available,
                'timestamp'     => $timestamp,
            );
        }

        usort($products_data, function($a, $b) {
            $a_time = isset($a['timestamp']) ? intval($a['timestamp']) : 0;
            $b_time = isset($b['timestamp']) ? intval($b['timestamp']) : 0;
            return $a_time <=> $b_time;
        });

        return $products_data;
    }

    private function convert_event_date_to_timestamp($date_string) {
        if (empty($date_string)) {
            return false;
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $date     = \DateTime::createFromFormat('d-m-Y', $date_string, $timezone);

        if ($date instanceof \DateTime) {
            $date->setTime(23, 59, 59);
            return $date->getTimestamp();
        }

        $timestamp = strtotime($date_string);
        return $timestamp ? $timestamp : false;
    }

    private function convert_event_datetime_to_timestamp($date_string, $time_string = '') {
        if (empty($date_string)) {
            return false;
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $time_string = $this->normalize_time_string($time_string);

        if ($time_string) {
            $date = \DateTime::createFromFormat('d-m-Y H:i', trim($date_string) . ' ' . $time_string, $timezone);
            if ($date instanceof \DateTime) {
                return $date->getTimestamp();
            }
        }

        return $this->convert_event_date_to_timestamp($date_string);
    }

    private function normalize_time_string($time_string) {
        if (empty($time_string)) {
            return '';
        }

        $time_string = preg_split('/[-–]/', $time_string);
        $time_string = trim(str_replace('Uhr', '', $time_string[0]));

        if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $time_string, $matches)) {
            $hour = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $minute = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            return $hour . ':' . $minute;
        }

        return '';
    }

    private function product_belongs_to_event($product_id, $event_id) {
        $product_event_id = intval(get_post_meta($product_id, '_event_id', true));
        return $product_event_id === intval($event_id);
    }


    private function log_error($message, $data = null) {
        $error_message = '[Event_Rebooking_Manager] ' . $message;

        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $error_message .= ' - ' . json_encode($data);
            } else {
                $error_message .= ' - ' . $data;
            }
        }

        error_log($error_message);
        return $error_message;
    }




}
