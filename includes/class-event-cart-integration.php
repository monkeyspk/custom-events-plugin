<?php
class Event_Cart_Integration {
    public function __construct() {
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_single_event_per_cart'), 10, 2);
        add_action('woocommerce_add_to_cart', array($this, 'add_participant_data_to_cart'), 10, 6);
        add_filter('woocommerce_get_item_data', array($this, 'display_participant_data_in_cart'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_participant_data_to_order'), 10, 4);
        add_action('woocommerce_after_order_itemmeta', array($this, 'display_participant_data_in_admin'), 10, 3);
        add_action('woocommerce_before_calculate_totals', array($this, 'adjust_cart_item_quantity'), 10, 1);
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_participant_email_uniqueness'), 10, 2);
    }

    /**
     * Verhindert, dass mehrere Event-Produkte (Workshop/Kurs) gleichzeitig im Warenkorb sind.
     * Pro Bestellung ist nur 1 Event erlaubt, da Status, Emails und Coach-Erinnerungen
     * auf ein einzelnes Event pro Order ausgelegt sind.
     */
    public function validate_single_event_per_cart($passed, $product_id) {
        if (!$passed) {
            return false;
        }

        // Prüfe ob das neue Produkt ein Event-Produkt ist
        $new_event_id = get_post_meta($product_id, '_event_id', true);
        if (empty($new_event_id)) {
            return true; // Kein Event-Produkt → kein Problem
        }

        // Prüfe ob bereits ein Event-Produkt im Warenkorb liegt
        if (!WC()->cart) {
            return true;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            $cart_product_id = $cart_item['product_id'];
            $cart_event_id = get_post_meta($cart_product_id, '_event_id', true);

            if (!empty($cart_event_id)) {
                wc_add_notice(
                    __('Du kannst nur ein Event pro Bestellung buchen. Bitte schliesse zuerst die aktuelle Buchung ab oder entferne das andere Event aus dem Warenkorb.', 'custom-events'),
                    'error'
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Validiert, dass eine E-Mail nicht für einen anderen Teilnehmer verwendet wird.
     * Verhindert, dass Geschwisterkinder mit derselben E-Mail gebucht werden und
     * dadurch bestehende Accounts im AcademyBoard überschrieben werden.
     */
    public function validate_participant_email_uniqueness($data, $errors) {
        $billing_email = isset($data['billing_email']) ? sanitize_email($data['billing_email']) : '';
        
        if (empty($billing_email)) {
            return;
        }

        $cart_participants = array();
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (isset($cart_item['event_participant_data']) && is_array($cart_item['event_participant_data'])) {
                foreach ($cart_item['event_participant_data'] as $participant) {
                    $cart_participants[] = array(
                        'vorname'      => strtolower(trim($participant['vorname'] ?? '')),
                        'name'         => strtolower(trim($participant['name'] ?? '')),
                        'geburtsdatum' => trim($participant['geburtsdatum'] ?? '')
                    );
                }
            }
        }

        if (empty($cart_participants)) {
            return;
        }

        $existing_participants = $this->get_existing_participants_by_email($billing_email);

        if (empty($existing_participants)) {
            return;
        }

        foreach ($cart_participants as $cart_participant) {
            if (empty($cart_participant['vorname']) && empty($cart_participant['name'])) {
                continue;
            }

            $is_known_participant = false;
            foreach ($existing_participants as $existing) {
                $existing_vorname = strtolower(trim($existing['vorname']));
                $existing_name = strtolower(trim($existing['name']));
                $existing_geb = trim($existing['geburtsdatum']);

                // Manuelle Buchungen: nur Name vergleichen (kein Geburtsdatum vorhanden)
                if ($existing_geb === 'manual') {
                    if ($cart_participant['vorname'] === $existing_vorname &&
                        $cart_participant['name'] === $existing_name) {
                        $is_known_participant = true;
                        break;
                    }
                } else {
                    if ($cart_participant['vorname'] === $existing_vorname &&
                        $cart_participant['name'] === $existing_name &&
                        $cart_participant['geburtsdatum'] === $existing_geb) {
                        $is_known_participant = true;
                        break;
                    }
                }
            }

            if (!$is_known_participant) {
                $participant_name = ucfirst($cart_participant['vorname']) . ' ' . ucfirst($cart_participant['name']);
                $existing_name = ucfirst($existing_participants[0]['vorname']) . ' ' . ucfirst($existing_participants[0]['name']);
                $contact_email = get_option('admin_email');
                
                $errors->add(
                    'participant_email_conflict',
                    sprintf(
                        __('Die E-Mail-Adresse %s ist bereits für einen anderen Teilnehmer (%s) registriert. Für %s verwende bitte eine andere E-Mail-Adresse oder kontaktiere uns unter %s.', 'custom-events'),
                        '<strong>' . esc_html($billing_email) . '</strong>',
                        esc_html($existing_name),
                        esc_html($participant_name),
                        esc_html($contact_email)
                    )
                );
                return;
            }
        }
    }

    /**
     * Holt alle Teilnehmer, die mit einer E-Mail verknüpft sind (aus bestehenden Bestellungen).
     * Berücksichtigt auch alte manuelle Buchungen ohne _event_participant_data.
     */
    private function get_existing_participants_by_email($email) {
        global $wpdb;

        $order_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = '_billing_email'
                AND meta_value = %s",
                $email
            )
        );

        if (empty($order_ids)) {
            return array();
        }

        $participants = array();
        $seen = array();
        // Nur aktive Status berücksichtigen (nicht gelöschte/stornierte)
        $inactive_statuses = array('cancelled', 'refunded', 'failed', 'trash', 'geloescht');

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            if (in_array($order->get_status(), $inactive_statuses)) {
                continue;
            }

            $found_participant_data = false;

            foreach ($order->get_items() as $item) {
                $participant_data = $item->get_meta('_event_participant_data');
                if (!empty($participant_data) && is_array($participant_data)) {
                    $found_participant_data = true;
                    foreach ($participant_data as $participant) {
                        $key = strtolower(trim($participant['vorname'] ?? '')) . '|' .
                               strtolower(trim($participant['name'] ?? '')) . '|' .
                               trim($participant['geburtsdatum'] ?? '');

                        if (!isset($seen[$key])) {
                            $participants[] = array(
                                'vorname'      => $participant['vorname'] ?? '',
                                'name'         => $participant['name'] ?? '',
                                'geburtsdatum' => $participant['geburtsdatum'] ?? ''
                            );
                            $seen[$key] = true;
                        }
                    }
                }
            }

            // Alte manuelle Buchungen ohne _event_participant_data:
            // Billing-Name als Teilnehmer verwenden
            if (!$found_participant_data) {
                $first = strtolower(trim($order->get_billing_first_name()));
                $last = strtolower(trim($order->get_billing_last_name()));
                $key = $first . '|' . $last . '|manual';

                if (!empty($first) && !empty($last) && !isset($seen[$key])) {
                    $participants[] = array(
                        'vorname'      => $order->get_billing_first_name(),
                        'name'         => $order->get_billing_last_name(),
                        'geburtsdatum' => 'manual'
                    );
                    $seen[$key] = true;
                }
            }
        }

        return $participants;
    }

    /**
     * Teilnehmerdaten und Event‑Details (inkl. venuespezifischer Daten) zum Warenkorb hinzufügen
     */
     public function add_participant_data_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
         if (isset($_POST['event_id'])) {
             $event_id = absint($_POST['event_id']);
             // Produkt‑ und Event‑ID speichern
             WC()->cart->cart_contents[$cart_item_key]['event_product_id'] = $product_id;
             WC()->cart->cart_contents[$cart_item_key]['event_id'] = $event_id;

             // Event-Preis speichern für Warenkorb-Override
             $event_price = get_post_meta($event_id, '_event_price', true);
             if ($event_price !== '' && $event_price !== false) {
                 WC()->cart->cart_contents[$cart_item_key]['event_custom_price'] = floatval($event_price);
             }


             // HIER DEN DEBUG-CODE EINFÜGEN:
    error_log("DEBUG Cart: Product ID: " . $product_id);
    error_log("DEBUG Cart: Event ID: " . $event_id);

             // Teilnehmerdaten speichern
             if (isset($_POST['event_participant_name'], $_POST['event_participant_vorname'], $_POST['event_participant_geburtsdatum'])) {
                 $participant_data = array();
                 $filled_fields = 0;
                 $participant_count = count($_POST['event_participant_name']);
                 for ($i = 0; $i < $participant_count; $i++) {
                     $name = sanitize_text_field($_POST['event_participant_name'][$i]);
                     $vorname = sanitize_text_field($_POST['event_participant_vorname'][$i]);
                     $geburtsdatum = sanitize_text_field($_POST['event_participant_geburtsdatum'][$i]);
                     if (!empty($name) || !empty($vorname) || !empty($geburtsdatum)) {
                         $participant_data[] = array(
                             'name'         => $name,
                             'vorname'      => $vorname,
                             'geburtsdatum' => $geburtsdatum
                         );
                         $filled_fields++;
                     }
                 }
                 WC()->cart->cart_contents[$cart_item_key]['event_participant_data'] = $participant_data;
                 WC()->cart->cart_contents[$cart_item_key]['quantity'] = $filled_fields;
             }

             // Event-Datum aus dem Produkt
             $event_date = get_post_meta($product_id, '_event_date', true);
             error_log("DEBUG: Event-Datum aus Produkt: '" . $event_date . "'");

             // Gesamte Termine abrufen
             $event_dates = get_post_meta($event_id, '_event_dates', true);
             error_log("DEBUG: _event_dates: " . print_r($event_dates, true));

             $venue_data = array();
             if (!empty($event_dates) && is_array($event_dates)) {
                 foreach ($event_dates as $date_info) {
                     // Logge den aktuellen Eintrag
                     error_log("DEBUG: Vergleiche Termin: '" . trim($date_info['date']) . "' mit Event-Datum: '" . trim($event_date) . "'");
                     if (trim($date_info['date']) === trim($event_date)) {
                         $venue_data = array(
                             'venue'     => isset($date_info['venue']) ? $date_info['venue'] : '',
                             'venue_lat' => isset($date_info['venue_lat']) ? $date_info['venue_lat'] : '',
                             'venue_lng' => isset($date_info['venue_lng']) ? $date_info['venue_lng'] : '',
                         );
                         error_log("DEBUG: Treffpunkt gefunden für Datum '" . $event_date . "': " . print_r($venue_data, true));
                         break;
                     }
                 }
             } else {
                 error_log("DEBUG: Keine Termine vorhanden.");
             }


             // Jetzt in minimal_event_details übernehmen
             $real_event_id = get_post_meta($product_id, '_event_id', true);
             $event_title = $real_event_id ? get_the_title($real_event_id) : get_the_title($product_id);

             $minimal_details = array(
                 'title'     => $event_title,
                 'date'      => $event_date,
                 'venue'     => isset($venue_data['venue']) ? $venue_data['venue'] : '',
                 'venue_lat' => isset($venue_data['venue_lat']) ? $venue_data['venue_lat'] : '',
                 'venue_lng' => isset($venue_data['venue_lng']) ? $venue_data['venue_lng'] : '',
             );
             WC()->cart->cart_contents[$cart_item_key]['minimal_event_details'] = $minimal_details;
             error_log("DEBUG [cart]: minimal_event_details: " . print_r($minimal_details, true));
         }
     }


    /**
     * Anzeige der Teilnehmer‑ und Event‑Daten im Warenkorb (Frontend)
     */
    public function display_participant_data_in_cart($item_data, $cart_item) {
        static $processed_items = array();
        $cart_item_key = md5(serialize($cart_item));
        if (isset($processed_items[$cart_item_key])) {
            return $item_data;
        }

        if (isset($cart_item['event_participant_data'])) {
            foreach ($cart_item['event_participant_data'] as $index => $participant) {
                $item_data[] = array(
                    'key'   => sprintf(__('Teilnehmer %d', 'your-text-domain'), $index + 1),
                    'value' => sprintf('%s %s (%s)', $participant['vorname'], $participant['name'], $participant['geburtsdatum'])
                );
            }
        }

        if (isset($cart_item['minimal_event_details'])) {
            if (!empty($cart_item['minimal_event_details']['title'])) {
                $item_data[] = array(
                    'key'   => __('Event', 'your-text-domain'),
                    'value' => esc_html($cart_item['minimal_event_details']['title'])
                );
            }
            if (!empty($cart_item['minimal_event_details']['date'])) {
                $item_data[] = array(
                    'key'   => __('Datum', 'your-text-domain'),
                    'value' => esc_html($cart_item['minimal_event_details']['date'])
                );
            }
            if (!empty($cart_item['minimal_event_details']['venue'])) {
                $item_data[] = array(
                    'key'   => __('Treffpunkt', 'your-text-domain'),
                    'value' => esc_html($cart_item['minimal_event_details']['venue'])
                );
            }
        }

        $processed_items[$cart_item_key] = true;
        return $item_data;
    }

    /**
     * Speichert Teilnehmer‑ und Event‑Daten (inkl. venuespezifischer Daten) in der Bestellung
     */
    public function save_participant_data_to_order($item, $cart_item_key, $values, $order) {
        if (isset($values['event_participant_data'])) {
            if (!$item->meta_exists('_event_participant_data')) {
                $item->add_meta_data('_event_participant_data', $values['event_participant_data']);
            }
        }

        if (isset($values['event_product_id'])) {
            if (!$item->meta_exists('_event_product_id')) {
                $item->add_meta_data('_event_product_id', $values['event_product_id']);
            }
        }

        // Minimal Event‑Details (inkl. Treffpunkt) speichern
        if (isset($values['minimal_event_details'])) {
            $event_details = $values['minimal_event_details'];
            if (!empty($event_details['venue'])) {
                if (!$item->meta_exists('_event_venue')) {
                    $item->add_meta_data('_event_venue', $event_details['venue']);
                }
                if (!$item->meta_exists('_event_venue_lat')) {
                    $item->add_meta_data('_event_venue_lat', $event_details['venue_lat']);
                }
                if (!$item->meta_exists('_event_venue_lng')) {
                    $item->add_meta_data('_event_venue_lng', $event_details['venue_lng']);
                }
            }
        }

        if (isset($values['event_id'])) {
            $event_id = absint($values['event_id']);
            $event_description = get_post_meta($event_id, '_event_description', true);
            if ($event_description && !$item->meta_exists('_event_description')) {
                $item->add_meta_data('_event_description', $event_description);
            }
            // NEU: course_id für robuste Vertragstyp-Zuordnung
            $event_course_id = get_post_meta($event_id, '_event_course_id', true);
            if ($event_course_id && !$item->meta_exists('_event_course_id')) {
                $item->add_meta_data('_event_course_id', $event_course_id);
            }
            $event_whatsapp_link = get_post_meta($event_id, '_event_whatsapp_link', true);
            $event_title = get_the_title($event_id);
            $event_date  = get_post_meta($values['event_product_id'], '_event_date', true);
            $event_start = get_post_meta($event_id, '_event_start_time', true);
            $event_end   = get_post_meta($event_id, '_event_end_time', true);
            $event_time  = ($event_start && $event_end) ? $event_start . ' - ' . $event_end : '';
            $event_venue = get_post_meta($event_id, '_event_venue', true);
            $event_coach = get_post_meta($event_id, '_event_headcoach', true);
            $event_details = array(
                'title'    => $event_title,
                'date'     => $event_date,
                'time'     => $event_time,
                'venue'    => $event_venue,
                'coach'    => $event_coach,
            );

            foreach ($event_details as $key => $value) {
                if (!empty($value) && !$item->meta_exists("_event_{$key}")) {
                    $item->add_meta_data("_event_{$key}", $value);
                }
            }
        }
    }

    /**
     * Zeigt die Teilnehmer‑ und Event‑Daten im Admin-Bereich (Bestell-Details) an
     */
    public function display_participant_data_in_admin($item_id, $item, $product) {
        static $displayed_items = array();
        if (isset($displayed_items[$item_id])) {
            return;
        }

        $participant_data = $item->get_meta('_event_participant_data');
        $product_id = $item->get_meta('_event_product_id');
        $event_description = $item->get_meta('_event_description');

        if ($participant_data) {
            echo '<h4>' . __('Teilnehmerdaten', 'your-text-domain') . '</h4>';
            if ($product_id) {
                echo '<p><strong>' . __('Event Produkt-ID:', 'your-text-domain') . '</strong> ' . esc_html($product_id) . '</p>';
            }
            echo '<table class="event-participant-data" style="width:100%; border-collapse: collapse;">';
            echo '<tr><th style="border: 1px solid #ddd; padding: 8px;">' . __('Vorname', 'your-text-domain') . '</th><th style="border: 1px solid #ddd; padding: 8px;">' . __('Name', 'your-text-domain') . '</th><th style="border: 1px solid #ddd; padding: 8px;">' . __('Geburtsdatum', 'your-text-domain') . '</th></tr>';
            foreach ($participant_data as $participant) {
                echo '<tr>';
                echo '<td style="border: 1px solid #ddd; padding: 8px;">' . esc_html($participant['vorname']) . '</td>';
                echo '<td style="border: 1px solid #ddd; padding: 8px;">' . esc_html($participant['name']) . '</td>';
                echo '<td style="border: 1px solid #ddd; padding: 8px;">' . esc_html($participant['geburtsdatum']) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }

        $event_title    = $item->get_meta('_event_title');
        $event_date     = $item->get_meta('_event_date');
        $event_time     = $item->get_meta('_event_time');
        $event_venue    = $item->get_meta('_event_venue');
        $event_venue_lat = $item->get_meta('_event_venue_lat');
        $event_venue_lng = $item->get_meta('_event_venue_lng');
        $event_coach    = $item->get_meta('_event_coach');
        $event_coach_email = $item->get_meta('_event_coach_email');  // Neue Zeile
        $event_description = $item->get_meta('_event_description');
        $event_whatsapp_link = $item->get_meta('_event_whatsapp_link');

        if ($event_title || $event_date || $event_time || $event_venue || $event_coach || $event_description) {
            echo '<h4>' . __('Informationen', 'your-text-domain') . '</h4>';
            if ($event_title) {
                echo '<p><strong>' . __('Event:', 'your-text-domain') . '</strong> ' . esc_html($event_title) . '</p>';
            }
            if ($event_date) {
                echo '<p><strong>' . __('Datum:', 'your-text-domain') . '</strong> ' . esc_html($event_date) . '</p>';
            }
            if ($event_time) {
                echo '<p><strong>' . __('Zeit:', 'your-text-domain') . '</strong> ' . esc_html($event_time) . '</p>';
            }
            if ($event_venue) {
                echo '<p><strong>' . __('Treffpunkt:', 'your-text-domain') . '</strong> ' . esc_html($event_venue);
                if ($event_venue_lat && $event_venue_lng) {
                    $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($event_venue_lat . ',' . $event_venue_lng);
                    echo ' (<a href="' . esc_url($maps_url) . '" target="_blank">Karte öffnen</a>)';
                }
                echo '</p>';
            }
            if ($event_coach) {
                echo '<p><strong>' . __('Coach:', 'your-text-domain') . '</strong> ' . esc_html($event_coach) . '</p>';
            }
            if (!empty($event_coach_email)) {
                echo '<p><strong>' . __('Coach Email:', 'your-text-domain') . '</strong> ' . esc_html($event_coach_email) . '</p>';  // Neue Zeile
              } 
            if ($event_description) {
                echo '<p><strong>' . __('Beschreibung:', 'your-text-domain') . '</strong> ' . esc_html($event_description) . '</p>';
            }
            if ($event_whatsapp_link) {
                echo '<p><strong>' . __('WhatsApp-Link:', 'your-text-domain') . '</strong> <a href="' . esc_url($event_whatsapp_link) . '">' . esc_html($event_whatsapp_link) . '</a></p>';
            }
        }

        $displayed_items[$item_id] = true;
    }

    /**
     * Passt die Artikelanzahl im Warenkorb anhand der Teilnehmeranzahl an
     */
    public function adjust_cart_item_quantity($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['event_participant_data'])) {
                $cart_item['quantity'] = count($cart_item['event_participant_data']);
            }
            // Event-Preis überschreiben (wie bei Gutscheinen)
            if (isset($cart_item['event_custom_price'])) {
                $cart_item['data']->set_price($cart_item['event_custom_price']);
            }
        }
    }
}

// Fix: Entfernt - Instanziierung erfolgt bereits in custom-events-plugin.php
// new Event_Cart_Integration();

add_action('woocommerce_checkout_create_order_line_item', 'add_full_event_info_to_order', 10, 4);
function add_full_event_info_to_order($item, $cart_item_key, $values, $order) {
    error_log("DEBUG ORDER: minimal_event_details: " . print_r($values['minimal_event_details'], true));

    $product_id = $values['product_id'];
    $event_id   = get_post_meta($product_id, '_event_id', true);

    if ($event_id) {
        // Hole zuerst alle Coach-bezogenen Daten
        $event_coach = get_post_meta($event_id, '_event_headcoach', true);
        $event_coach_img = get_post_meta($event_id, '_event_headcoach_image_url', true);
        $event_coach_phone = get_post_meta($event_id, '_event_headcoach_phone', true);
        $event_coach_email = get_post_meta($event_id, '_event_headcoach_email', true);  // Neue Zeile



        // Hole die Zeitinformationen
        $event_start = get_post_meta($event_id, '_event_start_time', true);
        $event_end = get_post_meta($event_id, '_event_end_time', true);
        $event_time = ($event_start && $event_end) ? $event_start . ' - ' . $event_end : '';

        // Falls minimal_event_details vorhanden, diese verwenden:
        if (isset($values['minimal_event_details']) && !empty($values['minimal_event_details']['venue'])) {
            $event_title = $values['minimal_event_details']['title'];
            $event_date  = $values['minimal_event_details']['date'];
            $event_venue = $values['minimal_event_details']['venue'];
            $event_venue_lat = $values['minimal_event_details']['venue_lat'];
            $event_venue_lng = $values['minimal_event_details']['venue_lng'];
            error_log("DEBUG ORDER: Nutze minimal_event_details - Treffpunkt: " . $event_venue);
        } else {
            // Wenn keine minimal_event_details vorhanden, versuche die terminspezifischen Daten zu laden
            $event_title = get_the_title($event_id);
            $event_date  = get_post_meta($product_id, '_event_date', true);

            // Hole alle Event-Termine
            $event_dates = get_post_meta($event_id, '_event_dates', true);
            $venue_data = array(
                'venue' => '',
                'venue_lat' => '',
                'venue_lng' => ''
            );

            if (!empty($event_dates) && is_array($event_dates)) {
                foreach ($event_dates as $date_info) {
                    if (trim($date_info['date']) === trim($event_date)) {
                        $venue_data = array(
                            'venue' => isset($date_info['venue']) ? $date_info['venue'] : '',
                            'venue_lat' => isset($date_info['venue_lat']) ? $date_info['venue_lat'] : '',
                            'venue_lng' => isset($date_info['venue_lng']) ? $date_info['venue_lng'] : ''
                        );
                        break;
                    }
                }
            }

            $event_venue = $venue_data['venue'];
            $event_venue_lat = $venue_data['venue_lat'];
            $event_venue_lng = $venue_data['venue_lng'];
            error_log("DEBUG ORDER: Fallback: Treffpunkt aus _event_dates für Datum {$event_date}: " . $event_venue);
        }

        $event_title_clean = wp_strip_all_tags($event_title);
        $event_title_clean = preg_replace('/\s+/', ' ', trim($event_title_clean));

        // Basis Event-Daten speichern
        $item->add_meta_data('_event_title', $event_title);
        $item->add_meta_data('_event_title_clean', $event_title_clean); // Speichere den bereinigten Titel
        $item->add_meta_data('_event_date', $event_date);
        $item->add_meta_data('_event_time', $event_time);
        $item->add_meta_data('_event_venue', $event_venue);
        $item->add_meta_data('_event_coach', $event_coach);

        // Coach-spezifische Daten speichern
        if (!empty($event_coach_img)) {
            $item->add_meta_data('_event_coach_image', $event_coach_img);
        }
        if (!empty($event_coach_phone)) {
            $item->add_meta_data('_event_coach_phone', $event_coach_phone);
        }
        if (!empty($event_coach_email)) {
    $item->add_meta_data('_event_coach_email', $event_coach_email);  // Neue Zeile
}

        // Standort-Koordinaten speichern
        if (!empty($event_venue_lat) && !empty($event_venue_lng)) {
            $item->add_meta_data('_event_venue_lat', $event_venue_lat);
            $item->add_meta_data('_event_venue_lng', $event_venue_lng);
        }

        // Zusätzliche Informationen
        $event_description = get_post_meta($event_id, '_event_description', true);
        if ($event_description && !$item->meta_exists('_event_description')) {
            $item->add_meta_data('_event_description', $event_description);
        }
        // NEU: course_id für robuste Vertragstyp-Zuordnung
        $event_course_id = get_post_meta($event_id, '_event_course_id', true);
        if ($event_course_id && !$item->meta_exists('_event_course_id')) {
            $item->add_meta_data('_event_course_id', $event_course_id);
        }

        $event_whatsapp_link = get_post_meta($event_id, '_event_whatsapp_link', true);
        if (!empty($event_whatsapp_link)) {
            $item->add_meta_data('_event_whatsapp_link', $event_whatsapp_link);
        }

        // Workshop-Erkennung: event_category Taxonomie prüfen
        $is_workshop = 0;
        $categories = wp_get_object_terms($event_id, 'event_category', array('fields' => 'all'));
        if (!is_wp_error($categories) && !empty($categories)) {
            $angebot_term = get_term_by('slug', 'angebot', 'event_category');
            if ($angebot_term) {
                foreach ($categories as $cat) {
                    if ((int) $cat->parent === (int) $angebot_term->term_id && $cat->slug !== 'probetraining') {
                        $is_workshop = 1;
                        break;
                    }
                }
            }
        }
        $item->add_meta_data('_event_is_workshop', $is_workshop);
    }

    // Teilnehmerdaten speichern
    if (isset($values['event_participant_data']) && !$item->meta_exists('_event_participant_data')) {
        $item->add_meta_data('_event_participant_data', $values['event_participant_data']);
    }

    // AB Contract Data für AcademyBoard erstellen und speichern
    if (isset($values['event_participant_data']) && !empty($values['event_participant_data'])) {
        // Nimm den ersten Teilnehmer als Hauptkontakt
        $first_participant = $values['event_participant_data'][0];
        
        // Hole Billing-Daten aus der Order für Vertragsdaten
        $ab_contract_data = (object) array(
            'vorname' => $first_participant['vorname'],
            'nachname' => $first_participant['name'],
            'geburtsdatum' => $first_participant['geburtsdatum'],
            // Erziehungsberechtigter aus Billing-Daten
            'erziehungsberechtigter_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'strasse' => $order->get_billing_address_1(),
            'hausnummer' => '', // Könnte aus address_1 extrahiert werden
            'plz' => $order->get_billing_postcode(),
            'ort' => $order->get_billing_city(),
            'email' => $order->get_billing_email(),
            'telefon' => $order->get_billing_phone()
        );
        
        // Speichere als _ab_contract_data für AcademyBoard-Kompatibilität
        $item->add_meta_data('_ab_contract_data', $ab_contract_data);
    }
}

?>
