<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('woocommerce_before_thankyou', 'show_event_info_with_order_meta', 20);
function show_event_info_with_order_meta($order_id) {
    if (!$order_id) return;

    $order = wc_get_order($order_id);

    // Prüfe, ob die Bestellung ein Event-Produkt enthält
$has_event_product = false;
foreach ($order->get_items() as $item) {
    // Prüfe auf Event-Metadaten (nur Event-Produkte haben diese)
    if ($item->get_meta('_event_title') || $item->get_meta('_event_date') || $item->get_meta('_event_participant_data')) {
        $has_event_product = true;
        break;
    }
}

// Wenn keine Event-Produkte gefunden wurden, modifiziere die Dankesseite nicht
if (!$has_event_product) {
    return;
}

    // Versuche, den ersten Teilnehmer für eine persönliche Ansprache zu ermitteln
    $participant_name_full = '';
    foreach ($order->get_items() as $item) {
        $participant_data = $item->get_meta('_event_participant_data', true);
        if (!empty($participant_data) && is_array($participant_data)) {
            $first_participant = $participant_data[0];
            $vorname = !empty($first_participant['vorname']) ? $first_participant['vorname'] : '';
            $nachname = !empty($first_participant['name']) ? $first_participant['name'] : '';
            $participant_name_full = trim($vorname . ' ' . $nachname);
            break;
        }
    }

    echo '<div class="booking-info-wrapper">';
    echo '<div class="booking-info-container">';

    if (!empty($participant_name_full)) {
        echo '<h3>' . sprintf(__('Hey %s, wir freuen uns auf deinen Besuch im Training!', 'your-text-domain'), esc_html($participant_name_full)) . '</h3>';
    } else {
        echo '<h3>' . __('Informationen zu deiner Buchung', 'your-text-domain') . '</h3>';
    }

    foreach ($order->get_items() as $item_id => $item) {
        $event_date = $item->get_meta('_event_date');
        $event_time = $item->get_meta('_event_time');
        $event_venue = $item->get_meta('_event_venue');
        $event_coach = $item->get_meta('_event_coach');
        $event_coach_img = $item->get_meta('_event_coach_image');
        $event_coach_phone = $item->get_meta('_event_coach_phone');
        $event_venue_lat = $item->get_meta('_event_venue_lat');
        $event_venue_lng = $item->get_meta('_event_venue_lng');
        $event_title = $item->get_meta('_event_title');

        // Überspringe Artikel, die keine Event-Daten haben
        if (!$event_title && !$event_date) {
            continue;
        }

        if ($event_time) {
            $time_parts = explode(' - ', $event_time);
            if (count($time_parts) === 2) {
                $start_time = strtotime($time_parts[0]);
                $end_time = strtotime($time_parts[1]);
                $formatted_start = date('H:i', $start_time);
                $formatted_end = date('H:i', $end_time);
                $event_time = $formatted_start . ' - ' . $formatted_end;
            }
        }

        echo '<div class="info-grid">';
        echo '<div class="info-box">';
        echo '<h4><span class="icon-training"></span> ' . esc_html($event_title) . '</h4>';
        if ($event_date) {
            $formatted_date = str_replace('-', '.', $event_date);
            echo '<div class="event-detail"><strong>' . __('Datum:', 'your-text-domain') . '</strong> ' . esc_html($formatted_date) . '</div>';
        }
        if ($event_time) {
            echo '<div class="event-detail"><strong>' . __('Zeit:', 'your-text-domain') . '</strong> ' . esc_html($event_time) . '</div>';
        }
        echo '<p class="note">Bitte sei 10 Minuten vor Beginn am Treffpunkt, damit wir pünktlich starten können.</p>';
        echo '</div>';

        echo '<div class="info-box">';
        echo '<h4><span class="icon-location"></span> ' . __('Treffpunkt', 'your-text-domain') . '</h4>';
        if ($event_venue) {
            if (!empty($event_venue_lat) && !empty($event_venue_lng)) {
                $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($event_venue_lat . ',' . $event_venue_lng);
                echo '<div class="event-detail"><a href="' . esc_url($maps_url) . '" target="_blank" class="maps-link">' . esc_html($event_venue) . '</a></div>';
            } else {
                echo '<div class="event-detail">' . esc_html($event_venue) . '</div>';
            }
        }
        echo '<p class="note">Unser Training findet bei jedem Wetter draußen statt. Bitte kleide dich dementsprechend wetterfest und bring ausreichend zu trinken mit, damit du bestens vorbereitet bist.</p>';
        echo '</div>';

        echo '<div class="info-box">';
        echo '<h4><span class="icon-coach"></span> ' . __('Dein Coach', 'your-text-domain') . '</h4>';
        if ($event_coach) {
            echo '<div class="coach-info">';
            if (!empty($event_coach_img)) {
                echo '<div class="coach-image"><img src="' . esc_url($event_coach_img) . '" alt="' . esc_attr($event_coach) . '"></div>';
            }
            echo '<div class="coach-details">';
            echo '<strong>' . esc_html($event_coach) . '</strong>';
            if (!empty($event_coach_phone)) {
                echo ' ' . __('Im Notfall erreichst du', 'your-text-domain') . ' ' . esc_html($event_coach) . ' ' . __('unter:', 'your-text-domain') . ' <a href="tel:' . esc_attr($event_coach_phone) . '">' . esc_html($event_coach_phone) . '</a>';
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo '</div>';
    }

    $order_number = $order->get_order_number();
    $order_date   = wc_format_datetime($order->get_date_created());
    $order_email  = $order->get_billing_email();

    echo '<div class="order-details-box">';
    echo '<h4>' . __('Bestelldetails', 'your-text-domain') . '</h4>';
    echo '<table class="order-details-table">';
    echo '<tr><th>' . __('Bestellnummer:', 'your-text-domain') . '</th><td>' . esc_html($order_number) . '</td></tr>';
    echo '<tr><th>' . __('Bestelldatum:', 'your-text-domain') . '</th><td>' . esc_html($order_date) . '</td></tr>';
    echo '<tr><th>' . __('E-Mail-Adresse:', 'your-text-domain') . '</th><td>' . esc_html($order_email) . '</td></tr>';
    echo '</table>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
}
?>
