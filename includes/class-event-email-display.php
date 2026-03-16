<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('woocommerce_checkout_create_order_line_item', 'add_full_event_info_to_order', 10, 4);
function add_full_event_info_to_order($item, $cart_item_key, $values, $order) {
    error_log("DEBUG ORDER: minimal_event_details: " . print_r($values['minimal_event_details'], true));

    $product_id = $values['product_id'];
    $event_id   = get_post_meta($product_id, '_event_id', true);

    if ($event_id) {
        // Hole zuerst alle Coach-bezogenen Daten
        $event_coach = get_post_meta($event_id, '_event_headcoach', true);
        $event_coach_img = get_post_meta($event_id, '_event_coach_image', true);
        $event_coach_phone = get_post_meta($event_id, '_event_headcoach_phone', true);
        $event_coach_email = get_post_meta($event_id, '_event_headcoach_email', true);  // Korrigiert


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
