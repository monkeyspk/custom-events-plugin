<?php
if (!defined('ABSPATH')) {
    exit;
}

class Event_Course_Import {

    /**
     * Importiert Kurse und Workshops von der AcademyBoard API in den angebot CPT.
     *
     * @return array ['imported' => int, 'updated' => int, 'errors' => string[]]
     */
    public static function run() {
        $result = ['imported' => 0, 'updated' => 0, 'errors' => [], 'log' => []];

        if (!defined('EVENT_API_TOKEN')) {
            $result['errors'][] = 'EVENT_API_TOKEN nicht definiert';
            return $result;
        }

        if (!post_type_exists('angebot')) {
            $result['errors'][] = 'CPT angebot nicht registriert (Theme aktiv?)';
            return $result;
        }

        $args = [
            'timeout'   => 120,
            'sslverify' => true,
        ];

        $base_url = 'https://academyboard.parkourone.com/api/event/dates?token=' . EVENT_API_TOKEN;
        $date_to  = date('Y-m-d', strtotime('+24 months'));

        // Zwei Calls: course + workshop (PHP's &type= überschreibt sich bei doppeltem Key)
        $all_events = [];
        foreach (['course', 'workshop'] as $type) {
            $url      = $base_url . '&type=' . $type . '&dateTo=' . $date_to;
            $response = wp_remote_get($url, $args);

            if (is_wp_error($response)) {
                $result['errors'][] = "API-Fehler ($type): " . $response->get_error_message();
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (!is_array($data)) {
                $result['errors'][] = "Ungültige JSON-Antwort ($type)";
                continue;
            }

            $all_events = array_merge($all_events, $data);
        }

        if (empty($all_events)) {
            $result['log'][] = 'Keine Kurse/Workshops von API erhalten';
            error_log('[Course Import] Keine Kurse/Workshops von API erhalten');
            return $result;
        }

        $result['log'][] = count($all_events) . ' Einträge von API erhalten';

        // Kein Region-Filter für Kurse/Workshops — die sind regionsübergreifend
        // und sollen auf allen Standort-Seiten verfügbar sein.

        // Nach course_id gruppieren
        $grouped = self::group_by_course_id($all_events);

        $result['log'][] = count($grouped) . ' einzigartige Kurse/Workshops nach Gruppierung';
        error_log('[Course Import] ' . count($grouped) . ' Kurse/Workshops gefunden');

        foreach ($grouped as $course_id => $data) {
            try {
                $was_new = self::import_single($course_id, $data);
                if ($was_new) {
                    $result['imported']++;
                } else {
                    $result['updated']++;
                }
            } catch (Exception $e) {
                $result['errors'][] = "course_id $course_id: " . $e->getMessage();
                error_log('[Course Import] Fehler bei course_id ' . $course_id . ': ' . $e->getMessage());
            }
        }

        error_log('[Course Import] Fertig: ' . $result['imported'] . ' neu, ' . $result['updated'] . ' aktualisiert');
        return $result;
    }

    private static function group_by_course_id(array $events): array {
        $grouped = [];

        foreach ($events as $event) {
            $course_id = $event['course_id'] ?? '';
            if (empty($course_id)) {
                continue;
            }

            if (!isset($grouped[$course_id])) {
                $grouped[$course_id] = [
                    'info'  => $event,
                    'dates' => [],
                ];
            }

            if (!empty($event['date'])) {
                $grouped[$course_id]['dates'][] = [
                    'datum'           => $event['date'],
                    'start_time'      => $event['start_time'] ?? '',
                    'end_time'        => $event['end_time'] ?? '',
                    'venue'           => $event['venue'] ?? '',
                    'venue_lat'       => $event['venue_location']['lat'] ?? '',
                    'venue_lng'       => $event['venue_location']['lng'] ?? '',
                    'available_seats' => $event['available_seats'] ?? 0,
                ];
            }
        }

        return $grouped;
    }

    /**
     * @return bool true wenn neu erstellt, false wenn aktualisiert
     */
    private static function import_single(string $course_id, array $data): bool {
        $info  = $data['info'];
        $dates = $data['dates'];
        $name  = trim($info['name'] ?? '');

        if (empty($name)) {
            throw new Exception('Kein Name vorhanden');
        }

        // Existierendes Angebot suchen
        $existing = self::find_existing_angebot($course_id, $name);
        $is_new   = !$existing;

        $price     = $info['price'] ?? null;
        $has_price = $price !== null && floatval($price) > 0;
        $status    = $has_price ? 'publish' : 'draft';

        if ($is_new) {
            $post_id = wp_insert_post([
                'post_title'   => $name,
                'post_type'    => 'angebot',
                'post_status'  => $status,
                'post_content' => $info['description'] ?? '',
            ]);
            if (is_wp_error($post_id)) {
                throw new Exception('wp_insert_post fehlgeschlagen: ' . $post_id->get_error_message());
            }
        } else {
            $post_id = $existing->ID;
            $update  = [
                'ID'           => $post_id,
                'post_title'   => $name,
                'post_content' => $info['description'] ?? '',
            ];
            if ($is_new || !$has_price) {
                $update['post_status'] = $status;
            }
            wp_update_post($update);
        }

        // course_id für Sync-Identifikation
        update_post_meta($post_id, '_angebot_course_id', $course_id);
        update_post_meta($post_id, '_angebot_api_import', '1');

        // Beschreibung
        $description = $info['description'] ?? '';
        if (!empty($description)) {
            $kurz_existing = get_post_meta($post_id, '_angebot_kurzbeschreibung', true);
            if (empty($kurz_existing)) {
                $kurz = mb_strlen($description) > 160
                    ? mb_substr($description, 0, 157) . '...'
                    : $description;
                update_post_meta($post_id, '_angebot_kurzbeschreibung', $kurz);
            }
        }

        // Venue + Maps
        $venue = $info['venue'] ?? '';
        update_post_meta($post_id, '_angebot_wo', $venue);

        $lat = $info['venue_location']['lat'] ?? '';
        $lng = $info['venue_location']['lng'] ?? '';
        if ($lat && $lng) {
            update_post_meta($post_id, '_angebot_maps_link',
                'https://www.google.com/maps?q=' . urlencode($lat . ',' . $lng));
        }

        // Coach
        $headcoach = trim($info['headcoach'] ?? $info['headcoach_course'] ?? '');
        if (!empty($headcoach)) {
            update_post_meta($post_id, '_angebot_ansprechperson', $headcoach);
        }

        // Zeitinfo
        $start = $info['start_time'] ?? '';
        $end   = $info['end_time'] ?? '';
        if ($start) {
            $wann = substr($start, 0, 5);
            if ($end) {
                $wann .= ' – ' . substr($end, 0, 5) . ' Uhr';
            } else {
                $wann .= ' Uhr';
            }
            update_post_meta($post_id, '_angebot_wann', $wann);
        }

        // Preis (Anzeige-Text)
        if ($has_price) {
            update_post_meta($post_id, '_angebot_preis', number_format(floatval($price), 0, ',', '.') . ' CHF');
        }

        // Kategorie-Zuordnung (VOR Termine, da Buchungslogik davon abhängt)
        $is_workshop = !empty($info['is_workshop']);
        $is_course   = !empty($info['is_course']);
        $is_ferienkurs = $is_course && (
            stripos($name, 'ferienkurs') !== false ||
            stripos($name, 'ferien') !== false
        );

        if ($is_ferienkurs) {
            wp_set_object_terms($post_id, 'ferienkurs', 'angebot_kategorie');
            update_post_meta($post_id, '_angebot_is_ferienkurs', '1');
        } elseif ($is_workshop) {
            wp_set_object_terms($post_id, 'workshop', 'angebot_kategorie');
            update_post_meta($post_id, '_angebot_is_ferienkurs', '0');
        } else {
            wp_set_object_terms($post_id, 'kurs', 'angebot_kategorie');
            update_post_meta($post_id, '_angebot_is_ferienkurs', '0');
        }

        // Termine aufbauen
        $termine = [];
        usort($dates, function ($a, $b) {
            return strcmp($a['datum'], $b['datum']);
        });

        // Buchungsart + WC-Produkt:
        // Workshop: immer genau 1 Datum, 1 Preis → 1 WC-Produkt
        // Kurs/Ferienkurs: mehrere Daten, 1 Gesamtpreis → 1 WC-Produkt (Paket)
        // Beide nutzen _angebot_ferienkurs_produkt_id für das single-product.
        $buchungsart_existing = get_post_meta($post_id, '_angebot_buchungsart', true);

        foreach ($dates as $d) {
            $termine[] = self::build_termin_entry($d, $venue);
        }

        if ($buchungsart_existing === 'extern') {
            // Admin hat manuell extern gesetzt → nicht überschreiben
        } elseif ($has_price) {
            update_post_meta($post_id, '_angebot_buchungsart', 'woocommerce');
            $product_id = self::ensure_wc_product_single($post_id, $name, $price, $dates);
            update_post_meta($post_id, '_angebot_ferienkurs_produkt_id', $product_id);
        } else {
            if (empty($buchungsart_existing)) {
                update_post_meta($post_id, '_angebot_buchungsart', 'kontakt');
            }
        }

        update_post_meta($post_id, '_angebot_termine', $termine);

        // Region als Saison/Typ (damit es in Admin erkennbar ist)
        $region = $info['region'] ?? '';
        if (!empty($region)) {
            update_post_meta($post_id, '_angebot_saison', $region);
        }

        return $is_new;
    }

    private static function find_existing_angebot(string $course_id, string $name) {
        // Primär: nach course_id
        $query = new WP_Query([
            'post_type'      => 'angebot',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => '_angebot_course_id',
                    'value' => $course_id,
                ]
            ],
        ]);

        if ($query->have_posts()) {
            return $query->posts[0];
        }

        // Fallback: nach Titel
        $existing = get_page_by_title($name, OBJECT, 'angebot');
        return $existing ?: null;
    }

    private static function build_termin_entry(array $d, string $fallback_venue): array {
        $uhrzeit = '';
        if (!empty($d['start_time'])) {
            $uhrzeit = substr($d['start_time'], 0, 5);
            if (!empty($d['end_time'])) {
                $uhrzeit .= ' - ' . substr($d['end_time'], 0, 5);
            }
        }

        return [
            'datum'      => $d['datum'],
            'uhrzeit'    => $uhrzeit,
            'ort'        => $d['venue'] ?? $fallback_venue,
            'preis'      => '',
            'kapazitaet' => $d['available_seats'] ?? '',
            'produkt_id' => '',
        ];
    }

    /**
     * Kurs/Ferienkurs: EIN WC-Produkt für den Gesamtkurs (alle Daten als Paket).
     */
    private static function ensure_wc_product_single(int $angebot_id, string $name, $price, array $dates): int {
        if (!function_exists('wc_get_product')) {
            throw new Exception('WooCommerce nicht aktiv');
        }

        $existing_id = (int) get_post_meta($angebot_id, '_angebot_ferienkurs_produkt_id', true);
        $max_seats   = 0;
        foreach ($dates as $d) {
            $seats = intval($d['available_seats'] ?? 0);
            if ($seats > $max_seats) {
                $max_seats = $seats;
            }
        }

        if ($existing_id && wc_get_product($existing_id)) {
            $product = wc_get_product($existing_id);
            $product->set_regular_price(floatval($price));
            if ($max_seats > 0) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($max_seats);
                $product->set_stock_status('instock');
            }
            $product->save();
            return $existing_id;
        }

        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_regular_price(floatval($price));
        $product->set_virtual(true);
        if ($max_seats > 0) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($max_seats);
            $product->set_stock_status('instock');
        }
        $product_id = $product->save();
        update_post_meta($product_id, '_angebot_id', $angebot_id);

        return $product_id;
    }

}
