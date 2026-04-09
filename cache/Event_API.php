<?php
if (!defined('ABSPATH')) {
    exit;
}

class Event_API {
    private $json_path;

    // Wenn sich die Datenform im Cache ändert (z.B. neue Felder wie is_course),
    // diese Version erhöhen. Beim Plugin-Load wird der Cache neu geschrieben,
    // sobald die gespeicherte Version nicht mit der aktuellen matcht.
    const CACHE_SCHEMA_VERSION = 2;

    public function __construct() {
        $this->json_path = plugin_dir_path(__FILE__) . 'events.json';
        $this->maybe_create_cache_directory();

        // Hooks für API und JSON-Cache
        add_action('rest_api_init', array($this, 'register_rest_endpoints'));
        add_action('event_import_complete', array($this, 'update_json_cache'));
        add_action('woocommerce_product_set_stock', array($this, 'update_json_cache'));

        // Schema-Migration: Cache neu schreiben wenn Version inkompatibel
        add_action('init', array($this, 'maybe_migrate_cache'));
    }

    public function maybe_migrate_cache() {
        $stored_version = (int) get_option('event_api_cache_schema_version', 0);
        if ($stored_version !== self::CACHE_SCHEMA_VERSION) {
            $this->update_json_cache();
            update_option('event_api_cache_schema_version', self::CACHE_SCHEMA_VERSION);
            error_log('Event_API: Cache schema migrated to version ' . self::CACHE_SCHEMA_VERSION);
        }
    }

    private function maybe_create_cache_directory() {
        $cache_dir = dirname($this->json_path);
        if (!file_exists($cache_dir)) {
            $result = wp_mkdir_p($cache_dir);
            if (!$result) {
                error_log("Event_API: Failed to create cache directory");
                return;
            }
            chmod($cache_dir, 0755);
            file_put_contents($cache_dir . '/.htaccess', "Deny from all");
        }

        if (file_exists($this->json_path) && !is_writable($this->json_path)) {
            error_log("Event_API: JSON file exists but is not writable");
        }
    }

    public function register_rest_endpoints() {
        register_rest_route('events/v1', '/list', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_events'),
            'permission_callback' => '__return_true',
            'args' => array(
                'page' => array(
                    'default' => 1,
                    'sanitize_callback' => 'absint'
                ),
                'offer' => array(
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'age' => array(
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'location' => array(
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'weekday' => array(
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'klasse' => array(
               'sanitize_callback' => 'sanitize_text_field'
                ),
            )
        ));
    }

    public function get_events(WP_REST_Request $request) {
        error_log("🔍 API Filter-Werte: " . json_encode($request->get_params()));

        if (!file_exists($this->json_path)) {
            $this->update_json_cache();
        }

        $page = $request->get_param('page');
        $per_page = 15;

        $offer = $request->get_param('offer');
        $age = $request->get_param('age');
        $location = $request->get_param('location');
        $weekday = $request->get_param('weekday');
        $klasse_permalink = $request->get_param('klasse');
        $only_probetraining = (bool) $request->get_param('only_probetraining');


        $json_content = file_get_contents($this->json_path);
        $events = json_decode($json_content, true);

        if ($events === null && json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('invalid_json', 'Die JSON-Datei ist ungültig oder leer.', ['status' => 500]);
        }

        if (!is_array($events)) {
            $events = array();
        }

        // Filtere vergangene Events - verschwindet 10 Minuten vor Startzeit
        $current_timestamp = current_time('timestamp');
        $events = array_filter($events, function($event) use ($current_timestamp) {
            $event_start = strtotime($event['date'] . ' ' . $event['start_time']);
            return ($event_start - 600) >= $current_timestamp; // 600 Sek = 10 Min
        });


        if (!empty($klasse_permalink)) {
    $events = array_filter($events, function($event) use ($klasse_permalink) {
        return isset($event['permalink']) && $event['permalink'] === $klasse_permalink;
    });
    $events = array_values($events); // Array-Indizes neu indizieren
}

        // Nur Probetrainings — Kurse und Workshops rausfiltern.
        // Wird auf der /probetraining-buchen/ Seite hart aktiviert, damit Ferienkurse,
        // Sonntagstrainings etc. dort nicht erscheinen (unabhängig vom Publish-Status).
        if ($only_probetraining) {
            $events = array_filter($events, function($event) {
                return empty($event['is_workshop']) && empty($event['is_course']);
            });
            $events = array_values($events);
        }

        // Filtere nach Kategorien, falls Filter gesetzt
        if ($offer || $age || $location || $weekday) {
            $events = array_filter($events, function($event) use ($offer, $age, $location, $weekday) {
                $matches = true;
                $event_categories = isset($event['categories']) ? array_map('strtolower', $event['categories']) : [];
                $offer = strtolower($offer);
                $age = strtolower($age);
                $location = strtolower($location);
                $weekday = strtolower($weekday);
                if ($offer && !in_array($offer, $event_categories)) {
                    $matches = false;
                }
                if ($age) {
                    // Support compound slugs like "juniors-adults" — match if age appears in any category slug
                    $age_match = false;
                    foreach ($event_categories as $cat) {
                        if ($cat === $age || strpos($cat, $age) !== false) {
                            $age_match = true;
                            break;
                        }
                    }
                    if (!$age_match) $matches = false;
                }
                if ($location && !in_array($location, $event_categories)) {
                    $matches = false;
                }
                if ($weekday && !in_array($weekday, $event_categories)) {
                    $matches = false;
                }
                return $matches;
            });
            $events = array_values($events);
        }

        $events = array_values($events);
        $total_events = count($events);
        $offset = ($page - 1) * $per_page;
        $paged_events = array_slice($events, $offset, $per_page);

        return array(
            'events' => $paged_events,
            'total' => $total_events,
            'has_more' => ($offset + $per_page) < $total_events,
            'current_page' => $page,
            'pages' => ceil($total_events / $per_page)
        );
    }

    public function update_json_cache() {
        // Warten, falls ein Import gerade läuft
        if (defined('DOING_IMPORT') && DOING_IMPORT) {
            sleep(2);
        }

        $events = $this->generate_events_data();

        error_log("Event_API: Attempting to update cache");
        error_log("Event count: " . count($events));

        if (!empty($events)) {
            $result = file_put_contents($this->json_path, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if ($result === false) {
                error_log("Event_API: Error writing to JSON file");
            } else {
                error_log("Event_API: Successfully updated cache with " . $result . " bytes");
            }
        }
    }

    private function generate_events_data() {
        error_log("=== Generating Events Data Started ===");

        $args = array(
            'post_type' => 'event',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        );

        $events = array();
        $query = new WP_Query($args);
        error_log("WP_Query found posts: " . $query->found_posts);

        if ($query->have_posts()) {
            error_log("Iterating through posts...");
            while ($query->have_posts()) {
                $query->the_post();
                $event_id = get_the_ID();
                error_log("Processing Event ID: " . $event_id);

                $event_dates = get_post_meta($event_id, '_event_dates', true);
                error_log("Event dates for ID " . $event_id . ": " . print_r($event_dates, true));

                if (!empty($event_dates)) {
                    foreach ($event_dates as $date_info) {
                        // Event verschwindet 10 Minuten vor Startzeit
                        $event_start = strtotime($date_info['date'] . ' ' . $date_info['start_time']);
                        $cutoff = $event_start - 600; // 10 Minuten vorher
                        error_log("Checking date: " . $date_info['date'] . " start: " . $date_info['start_time'] . " (cutoff: " . date('H:i', $cutoff) . " vs now: " . current_time('H:i') . ")");
                        if ($cutoff < current_time('timestamp')) {
                            error_log("Skipping event (starts in <10min or past): " . $date_info['date']);
                            continue;
                        }
                        $product_id = $this->find_product($event_id, $date_info['date']);
                        if ($product_id) {
                            error_log("Found product ID: " . $product_id . " for event: " . $event_id);
                            $product = wc_get_product($product_id);
                            $events[] = $this->format_event_data($event_id, $product_id, $date_info, $product);
                        } else {
                            error_log("No product found for event ID: " . $event_id . " and date: " . $date_info['date']);
                        }
                    }
                } else {
                    error_log("No event dates found for ID: " . $event_id);
                    $start_date = get_post_meta($event_id, '_event_start_date', true);
                    error_log("Checking start date: " . $start_date);
                    if (!empty($start_date)) {
                        $end_time = get_post_meta($event_id, '_event_end_time', true);
                        $start_time_single = get_post_meta($event_id, '_event_start_time', true);
                        // Event verschwindet 10 Minuten vor Startzeit
                        $event_start_single = strtotime($start_date . ' ' . $start_time_single);
                        if (($event_start_single - 600) >= current_time('timestamp')) {
                            $product_id = $this->find_product($event_id, $start_date);
                            if ($product_id) {
                                error_log("Found product for single date event. Product ID: " . $product_id);
                                $product = wc_get_product($product_id);
                                $date_info = array(
                                    'date' => $start_date,
                                    'start_time' => get_post_meta($event_id, '_event_start_time', true),
                                    'end_time' => $end_time
                                );
                                $events[] = $this->format_event_data($event_id, $product_id, $date_info, $product);
                            } else {
                                error_log("No product found for single date event ID: " . $event_id);
                            }
                        } else {
                            error_log("Single date event is in the past: " . $start_date);
                        }
                    }
                }
            }
        }
        wp_reset_postdata();

        error_log("Final events count before sorting: " . count($events));

        if (!empty($events)) {
            usort($events, function($a, $b) {
                $date_a = strtotime($a['date'] . ' ' . $a['start_time']);
                $date_b = strtotime($b['date'] . ' ' . $b['start_time']);
                return $date_a - $date_b;
            });
            error_log("Events sorted successfully");
        }

        error_log("=== Generating Events Data Completed ===");
        return $events;
    }

    private function format_event_data($event_id, $product_id, $date_info, $product) {
        $stock = $product ? $product->get_stock_quantity() : 0;
        if ($product && !$product->is_in_stock()) {
            $stock = 0;
        }
        return array(
            'id' => $event_id,
            'product_id' => $product_id,
            'title' => get_the_title($event_id),
            'date' => $date_info['date'],
            'start_time' => substr($date_info['start_time'], 0, 5),
            'end_time' => substr($date_info['end_time'], 0, 5),
            'stock' => $stock,
            'price' => get_post_meta($event_id, '_event_price', true),
            'headcoach' => get_post_meta($event_id, '_event_headcoach', true),
            'description' => get_post_field('post_content', $event_id),
            'dropdown_info' => get_post_meta($event_id, '_event_dropdown_info', true),
            'categories' => wp_get_post_terms($event_id, 'event_category', array('fields' => 'slugs')),
            'venue' => isset($date_info['venue']) ? $date_info['venue'] : get_post_meta($event_id, '_event_venue', true),
            'venue_lat' => isset($date_info['venue_lat']) ? $date_info['venue_lat'] : get_post_meta($event_id, '_event_venue_lat', true),
            'venue_lng' => isset($date_info['venue_lng']) ? $date_info['venue_lng'] : get_post_meta($event_id, '_event_venue_lng', true),
            'permalink' => get_post_meta($event_id, '_event_permalink', true),
            'is_workshop' => (bool) get_post_meta($event_id, '_event_is_workshop', true),
            'is_course'   => (bool) get_post_meta($event_id, '_event_is_course', true)
        );
    }

    private function find_product($event_id, $date) {
        error_log("Finding product for Event ID: " . $event_id . ", Date: " . $date);
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
        if (!empty($products)) {
            error_log("Found product ID: " . $products[0]);
            return $products[0];
        } else {
            error_log("No product found for Event ID: " . $event_id . " and Date: " . $date);
            return false;
        }
    }
}
