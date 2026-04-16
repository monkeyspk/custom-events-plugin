<?php
/**
 * Plugin Name: Custom Events Plugin
 * Description: A plugin to manage and display custom events with WooCommerce integration.
 * Version: 1.7
 * Author: Pierre Biege
 */

if (!defined('ABSPATH')) {
    exit;
}

// Required files
include_once plugin_dir_path(__FILE__) . 'includes/functions.php';
include_once plugin_dir_path(__FILE__) . 'includes/class-event-cpt.php';
include_once plugin_dir_path(__FILE__) . 'includes/class-event-metaboxes.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-event-advanced-filter.php';
include_once plugin_dir_path(__FILE__) . 'includes/class-event-shortcodes.php';
include_once plugin_dir_path(__FILE__) . 'includes/class-event-dynamic-product-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/github-updater.php';
include_once plugin_dir_path(__FILE__) . 'includes/class-event-cart-integration.php';
require_once plugin_dir_path(__FILE__) . 'cache/Event_API.php';
include_once plugin_dir_path(__FILE__) . 'includes/event-email-display.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-onboarding.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-event-regions-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-event-rebooking-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-event-admin-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-event-term-reassignment.php';

// Deaktiviere WordPress Cron für unser Plugin
if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}

function custom_events_plugin_init() {
    new Event_CPT();
    new Event_Metaboxes();
    new Event_Shortcodes();
    new Event_Dynamic_Product_Handler();
    new Event_Cart_Integration();
    $regions_handler = new Event_Regions_Handler();
    $rebooking_manager = new Event_Rebooking_Manager();
    new Event_Term_Reassignment();
    Event_Admin_Page::init($regions_handler, $rebooking_manager);
}
add_action('plugins_loaded', 'custom_events_plugin_init');

// REST API Endpoint für Cron
add_action('rest_api_init', function () {
    register_rest_route('events/v1', '/cron-import', array(
        'methods' => 'POST',
        'callback' => 'handle_cron_import',
        'permission_callback' => function() {
            return check_cron_auth();
        }
    ));
});

function check_cron_auth() {
    $provided_token = isset($_SERVER['HTTP_X_CRON_TOKEN']) ? $_SERVER['HTTP_X_CRON_TOKEN'] : '';
    $expected_token = defined('EVENTS_CRON_TOKEN') ? EVENTS_CRON_TOKEN : '';

    if (empty($expected_token)) {
        error_log('EVENTS_CRON_TOKEN nicht definiert');
        return false;
    }

    return hash_equals($expected_token, $provided_token);
}

function custom_events_format_event_date($raw_date) {
    if (empty($raw_date)) {
        return '';
    }

    $timestamp = strtotime($raw_date);
    if ($timestamp === false) {
        return '';
    }

    return date('d-m-Y', $timestamp);
}

function custom_events_maybe_extend_time_limit($seconds = 300) {
    if (function_exists('set_time_limit')) {
        @set_time_limit($seconds);
    }
}

function custom_events_log_import($status, $message, $details = []) {
    $log = get_option('custom_events_import_log', []);
    array_unshift($log, [
        'time'    => current_time('mysql'),
        'status'  => $status, // 'success', 'error', 'started'
        'message' => $message,
        'details' => $details,
    ]);
    // Nur die letzten 20 Einträge behalten
    $log = array_slice($log, 0, 20);
    update_option('custom_events_import_log', $log, false);
}

function handle_cron_import(WP_REST_Request $request) {
    file_put_contents(__DIR__ . '/cron.log', date('Y-m-d H:i:s') . " - Cron wurde aufgerufen\n", FILE_APPEND);

    custom_events_maybe_extend_time_limit(600);
    if (function_exists('ini_set')) {
        @ini_set('memory_limit', '512M');
    }

    custom_events_log_import('started', 'Import gestartet (memory: ' . ini_get('memory_limit') . ', max_exec: ' . ini_get('max_execution_time') . 's)');

    // Shutdown-Handler: fängt Fatal Errors die try/catch nicht erreichen
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $msg = $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line'];
            custom_events_log_import('error', 'FATAL: ' . $msg);
            error_log('[Event Import] FATAL ERROR: ' . $msg);
        }
    });

    try {
        $args = array(
            'timeout' => 120,
            'sslverify' => true
        );

        error_log('Starting cron-triggered event import at ' . current_time('mysql'));

        if (!defined('EVENT_API_TOKEN')) {
            throw new Exception('EVENT_API_TOKEN not defined');
        }

        if (!defined('DOING_IMPORT')) {
            define('DOING_IMPORT', true);
        }

        $api_url = 'https://academyboard.parkourone.com/api/event/dates?token=' . EVENT_API_TOKEN;
        error_log('Requesting API URL: ' . preg_replace('/token=[^&]+/', 'token=***', $api_url));

        $response = wp_remote_get($api_url, $args);
        error_log('API Response received');

        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        $events = json_decode($body, true);

        if ($http_code !== 200) {
            throw new Exception('API returned HTTP ' . $http_code . ': ' . substr($body, 0, 200));
        }

        if (!is_array($events)) {
            $json_error = json_last_error_msg();
            error_log('[Event Import] JSON decode failed. Error: ' . $json_error . '. Body (first 500 chars): ' . substr($body, 0, 500));
            throw new Exception('API-Antwort ist kein gültiges JSON (' . $json_error . '). HTTP ' . $http_code);
        }

        error_log('Total events from API: ' . count($events));

        if (empty($events)) {
            throw new Exception('No events found in API response');
        }

        // Alle Events für 12 Monate importieren (kein Probetraining-Filter mehr)
        error_log('Events from API (12 Monate, kein Datumsfilter): ' . count($events));

        $regions_handler = new Event_Regions_Handler();
        $selected_regions = get_option('event_selected_regions', array());
        error_log('Selected regions: ' . print_r($selected_regions, true));

        $events = $regions_handler->filter_events_by_region($events);
        error_log('Events after region filtering: ' . count($events));

        if (empty($events)) {
            throw new Exception('No events found for selected regions');
        }

        $grouped_events = array();
        $imported_event_titles = array();
        error_log('Starting event grouping');

        foreach ($events as $event_data) {
            $event_identifier = isset($event_data['name']) ? trim($event_data['name']) : '';
            if (empty($event_identifier)) {
                continue;
            }

            $imported_event_titles[] = $event_identifier;

            if (!isset($grouped_events[$event_identifier])) {
                $grouped_events[$event_identifier] = array(
                    'info' => $event_data,
                    'dates' => array()
                );
            }

            $formatted_date = custom_events_format_event_date(isset($event_data['date']) ? $event_data['date'] : '');
            if ($formatted_date) {
                $grouped_events[$event_identifier]['dates'][] = array(
                    'date' => $formatted_date,
                    'start_time' => $event_data['start_time'],
                    'end_time' => $event_data['end_time'],
                    'venue' => isset($event_data['venue']) ? $event_data['venue'] : '',
                    'venue_lat' => isset($event_data['venue_location']['lat']) ? $event_data['venue_location']['lat'] : '',
                    'venue_lng' => isset($event_data['venue_location']['lng']) ? $event_data['venue_location']['lng'] : '',
                    'trail_seats' => isset($event_data['trail_seats']) ? $event_data['trail_seats'] : 0,
                    'available_seats' => isset($event_data['available_seats']) ? $event_data['available_seats'] : 0
                );
            }
        }
        error_log('Event grouping completed. Found ' . count($grouped_events) . ' unique identifiers');

        $imported_count = 0;
        $imported_course_ids = array(); // NEU: Tracking der importierten course_ids

        foreach ($grouped_events as $event_identifier => $data) {
          $course_id = isset($data['info']['course_id']) ? $data['info']['course_id'] : '';
          $existing_event = null;

          // NEU: PRIMÄR nach course_id suchen (robust gegen Namensänderungen)
          if (!empty($course_id)) {
              $imported_course_ids[] = $course_id; // Für Cleanup tracking

              $args = array(
                  'post_type' => 'event',
                  'post_status' => 'publish',
                  'posts_per_page' => 1,
                  'meta_query' => array(
                      array(
                          'key' => '_event_course_id',
                          'value' => $course_id,
                          'compare' => '='
                      )
                  )
              );
              $query = new WP_Query($args);

              if ($query->have_posts()) {
                  $existing_event = $query->posts[0];
                  error_log('Found event using course_id ' . $course_id . ': ' . $existing_event->post_title);

                  // Titel aktualisieren falls geändert
                  if ($existing_event->post_title !== $event_identifier) {
                      wp_update_post(array(
                          'ID' => $existing_event->ID,
                          'post_title' => $event_identifier
                      ));
                      error_log('Updated event title from "' . $existing_event->post_title . '" to "' . $event_identifier . '"');
                  }
              }
          }

          // FALLBACK: Nach Titel suchen
          if (!$existing_event) {
              $existing_event = get_page_by_title($event_identifier, OBJECT, 'event');
          }

          // FALLBACK 2: Nach _event_location_original suchen
          if (!$existing_event) {
              $args = array(
                  'post_type' => 'event',
                  'post_status' => 'publish',
                  'posts_per_page' => 1,
                  'meta_query' => array(
                      array(
                          'key' => '_event_location_original',
                          'value' => $event_identifier,
                          'compare' => '='
                      )
                  )
              );
              $query = new WP_Query($args);

              if ($query->have_posts()) {
                  $existing_event = $query->posts[0];
                  error_log('Found event using meta field: ' . $event_identifier);
              }
          }

          if (!$existing_event) {
                $event_id = wp_insert_post([
                    'post_title' => $event_identifier,
                    'post_type' => 'event',
                    'post_status' => 'publish',
                ]);
                update_post_meta($event_id, '_event_availability', $data['info']['trail_seats']);
                // Speichere den Originalnamen als Meta
                update_post_meta($event_id, '_event_location_original', $event_identifier);
                error_log('Created new event: ' . $event_identifier . ' with course_id: ' . $course_id);
            } else {
                $event_id = $existing_event->ID;
                error_log('Updating existing event: ' . $event_identifier);
            }



            update_post_meta($event_id, '_event_headcoach', $data['info']['headcoach']);
            update_post_meta($event_id, '_event_headcoach_image_url', $data['info']['headcoach_image_url']);
            update_post_meta($event_id, '_event_start_time', date('H:i', strtotime($data['info']['start_time'])));
            update_post_meta($event_id, '_event_end_time', date('H:i', strtotime($data['info']['end_time'])));
            update_post_meta($event_id, '_event_dates', $data['dates']);
            update_post_meta($event_id, '_event_venue', $data['info']['venue']);
            update_post_meta($event_id, '_event_price', $data['info']['price']);
            update_post_meta($event_id, '_manual_event', false);
            update_post_meta($event_id, '_event_headcoach_phone', $data['info']['headcoach_phone']);
            update_post_meta($event_id, '_event_headcoach_email', $data['info']['headcoach_email']);
            update_post_meta($event_id, '_event_venue_lat', isset($data['info']['venue_location']['lat']) ? $data['info']['venue_location']['lat'] : '');
            update_post_meta($event_id, '_event_venue_lng', isset($data['info']['venue_location']['lng']) ? $data['info']['venue_location']['lng'] : '');
            update_post_meta($event_id, '_event_whatsapp_link', $data['info']['whatsapp_link']);
            update_post_meta($event_id, '_event_region', $data['info']['region']);
            update_post_meta($event_id, '_event_description', $data['info']['description']);
            update_post_meta($event_id, '_event_is_workshop', isset($data['info']['is_workshop']) ? (int) $data['info']['is_workshop'] : 0);
            update_post_meta($event_id, '_event_is_course', isset($data['info']['is_course']) ? (int) $data['info']['is_course'] : 0);

            // NEU: course_id für robuste Vertragstyp-Zuordnung (unabhängig von Namensänderungen)
            if (isset($data['info']['course_id'])) {
                update_post_meta($event_id, '_event_course_id', $data['info']['course_id']);
            }

            if (!empty($data['info']['coaches'])) {
                update_post_meta($event_id, '_event_coaches', $data['info']['coaches']);
            }

            // Auto-Kategorisierung: Workshop / Kurs als Kinder von "angebot" zuweisen
            custom_events_sync_event_category($event_id, $data['info']);

            $imported_count++;
        }
        error_log('Updated metadata for all events');

        // Alte Events aufräumen
        $args = array(
            'post_type' => 'event',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_manual_event',
                    'value' => false,
                    'compare' => '='
                )
            )
        );


        $query = new WP_Query($args);
        error_log('Checking for events to clean up. Found: ' . $query->found_posts . ' total events');

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $event_id = get_the_ID();
                $event_title = get_the_title();
                $event_course_id = get_post_meta($event_id, '_event_course_id', true);
                $original_location = get_post_meta($event_id, '_event_location_original', true);

                // NEU: PRIMÄR nach course_id prüfen (robust gegen Namensänderungen)
                if (!empty($event_course_id)) {
                    if (in_array($event_course_id, $imported_course_ids)) {
                        // Event hat gültige course_id - NICHT löschen
                        continue;
                    } else {
                        // course_id nicht mehr in API - Event löschen
                        wp_delete_post($event_id, true);
                        error_log('Deleted event based on course_id ' . $event_course_id . ': ' . $event_title);
                        continue;
                    }
                }

                // FALLBACK: Prüfen nach original_location (für ältere Events ohne course_id)
                if (!empty($original_location)) {
                    if (!in_array($original_location, $imported_event_titles)) {
                        wp_delete_post($event_id, true);
                        error_log('Deleted event based on original location: ' . $original_location);
                    }
                } else {
                    // FALLBACK 2: Normalisiere den Titel, indem HTML-Entities dekodiert werden
                    $normalized_title = html_entity_decode($event_title);

                    // Prüfe, ob der normalisierte Titel in den importierten Titeln vorhanden ist
                    $found = false;
                    foreach ($imported_event_titles as $imported_title) {
                        if ($normalized_title === $imported_title || $event_title === $imported_title) {
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        wp_delete_post($event_id, true);
                        error_log('Deleted event: ' . $event_title);
                    }
                }
            }
            wp_reset_postdata();
        }

        do_action('event_import_complete');

        error_log('Starting product synchronization');
        $args = array(
            'post_type' => 'event',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'ID',
            'order' => 'ASC'
        );

        $events = get_posts($args);
        foreach ($events as $event) {
            wp_update_post(array(
                'ID' => $event->ID,
                'post_status' => 'publish'
            ));
            do_action('save_post_event', $event->ID, $event, true);
            error_log('Synchronized products for event: ' . $event->post_title);
        }

        error_log("Cron import completed. Imported/updated {$imported_count} events and synchronized products.");
        custom_events_log_import('success', "Import abgeschlossen: {$imported_count} Events importiert/aktualisiert", [
            'events_from_api' => count(json_decode(wp_remote_retrieve_body($response), true) ?: []),
            'events_imported' => $imported_count,
        ]);
        return new WP_REST_Response(['success' => true], 200);

    } catch (Exception $e) {
        error_log('Cron import failed: ' . $e->getMessage());
        custom_events_log_import('error', $e->getMessage());
        return new WP_REST_Response(['error' => $e->getMessage()], 500);
    }
}

function create_event_category_taxonomy() {
    register_taxonomy(
        'event_category',
        'event',
        array(
            'label' => __('Event Categories'),
            'rewrite' => array('slug' => 'event-category'),
            'hierarchical' => true,
            'show_admin_column' => true,
        )
    );
}
add_action('init', 'create_event_category_taxonomy', 0);

// Event-Kategorien ins ParkourONE-Menü einhängen
function ab_add_event_category_submenu() {
    add_submenu_page(
        'parkourone',
        'Event Kategorien',
        'Event Kategorien',
        'manage_options',
        'edit-tags.php?taxonomy=event_category&post_type=event'
    );
}
add_action('admin_menu', 'ab_add_event_category_submenu');

// ParkourONE-Menü als Parent highlighten wenn auf Event-Kategorien-Seite
function ab_event_category_parent_file($parent_file) {
    global $current_screen;
    if ($current_screen && $current_screen->taxonomy === 'event_category') {
        return 'parkourone';
    }
    return $parent_file;
}
add_filter('parent_file', 'ab_event_category_parent_file');

function custom_events_enqueue_scripts() {
    wp_enqueue_style('skeleton-simple', plugin_dir_url(__FILE__) . 'assets/css/skeleton-simple.css', array(), '1.0.0');
    wp_enqueue_script('skeleton-simple', plugin_dir_url(__FILE__) . 'assets/js/skeleton-simple.js', array('jquery'), '1.0.0', true);
    wp_enqueue_style('custom-events-style', plugin_dir_url(__FILE__) . 'assets/css/custom-events.css', array(), '1.0.0');
    wp_enqueue_script('custom-events-script', plugin_dir_url(__FILE__) . 'assets/js/custom-events.js', array('jquery'), '1.0.0', true);
    wp_localize_script('custom-events-script', 'customEventsParams', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('filter_events_nonce'),
        'currencySymbol' => get_woocommerce_currency_symbol() // Neue Zeile hinzufügen
    ));
}
add_action('wp_enqueue_scripts', 'custom_events_enqueue_scripts');

add_action('wp_ajax_sync_event_products', 'handle_product_sync');
function handle_product_sync() {
    check_ajax_referer('sync_products_nonce', 'nonce');

    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
    $batch_size = 5;

    $args = array(
        'post_type' => 'event',
        'posts_per_page' => $batch_size,
        'offset' => $offset,
        'post_status' => 'publish',
        'orderby' => 'ID',
        'order' => 'ASC'
    );

    $events = get_posts($args);
    $total_events = wp_count_posts('event')->publish;

    foreach ($events as $event) {
        wp_update_post(array(
            'ID' => $event->ID,
            'post_status' => 'publish'
        ));
        do_action('save_post_event', $event->ID, $event, true);
    }

    $done = ($offset + $batch_size) >= $total_events;
    $next_offset = $offset + $batch_size;

    if ($done) {
        do_action('event_import_complete');
    }

    wp_send_json(array(
        'success' => true,
        'done' => $done,
        'next_offset' => $next_offset,
        'total' => $total_events,
        'processed' => min($next_offset, $total_events)
    ));
}

function import_events_from_api() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized access', 'custom-events-plugin'), '', ['response' => 403]);
    }
    check_admin_referer('import_events_nonce');

    custom_events_maybe_extend_time_limit(600);
    if (function_exists('ini_set')) {
        @ini_set('memory_limit', '512M');
    }

    custom_events_log_import('started', 'Manueller Import gestartet');

    if (!defined('EVENT_API_TOKEN')) {
        error_log('EVENT_API_TOKEN nicht definiert');
        custom_events_log_import('error', 'EVENT_API_TOKEN nicht definiert');
        wp_redirect(admin_url('edit.php?post_type=event&import_status=failed'));
        exit;
    }

    error_log('Starting event import from external API...');

    $api_url = 'https://academyboard.parkourone.com/api/event/dates?token=' . EVENT_API_TOKEN;
    $args = array(
        'timeout' => 120,
        'sslverify' => true
    );
    $response = wp_remote_get($api_url, $args);

    if (is_wp_error($response)) {
        error_log('External API Error: ' . $response->get_error_message());
        custom_events_log_import('error', 'API-Fehler: ' . $response->get_error_message());
        wp_redirect(admin_url('edit.php?post_type=event&import_status=failed'));
        exit;
    }

    $body = wp_remote_retrieve_body($response);
    $http_code = wp_remote_retrieve_response_code($response);
    $events = json_decode($body, true);

    if (!is_array($events)) {
        $json_error = json_last_error_msg();
        error_log('[Event Import] JSON decode failed. Error: ' . $json_error . '. HTTP ' . $http_code . '. Body (first 500 chars): ' . substr($body, 0, 500));
        custom_events_log_import('error', 'API-Antwort ist kein gültiges JSON (' . $json_error . '). HTTP ' . $http_code);
        wp_redirect(admin_url('edit.php?post_type=event&import_status=failed'));
        exit;
    }

    error_log('Received external events count: ' . count($events));

    if (empty($events)) {
        error_log('No events found in external API response');
        custom_events_log_import('error', 'Keine Events in API-Antwort');
        wp_redirect(admin_url('edit.php?post_type=event&import_status=failed'));
        exit;
    }

    // Alle Events für 12 Monate importieren (kein Probetraining-Filter mehr)
    error_log('Events from API (12 Monate, kein Datumsfilter): ' . count($events));

    $grouped_events = array();
    $imported_event_titles = array();

    foreach ($events as $event_data) {
        $event_identifier = isset($event_data['name']) ? trim($event_data['name']) : '';
        if (empty($event_identifier)) {
            continue;
        }

        $imported_event_titles[] = $event_identifier;

        if (!isset($grouped_events[$event_identifier])) {
            $grouped_events[$event_identifier] = array(
                'info' => $event_data,
                'dates' => array()
            );
        }

        $formatted_date = custom_events_format_event_date(isset($event_data['date']) ? $event_data['date'] : '');
        if ($formatted_date) {
            $grouped_events[$event_identifier]['dates'][] = array(
                'date' => $formatted_date,
                'start_time' => $event_data['start_time'],
                'end_time' => $event_data['end_time'],
                'venue' => isset($event_data['venue']) ? $event_data['venue'] : '',
                'venue_lat' => isset($event_data['venue_location']['lat']) ? $event_data['venue_location']['lat'] : '',
                'venue_lng' => isset($event_data['venue_location']['lng']) ? $event_data['venue_location']['lng'] : '',
                'trail_seats' => isset($event_data['trail_seats']) ? $event_data['trail_seats'] : 0,
                'available_seats' => isset($event_data['available_seats']) ? $event_data['available_seats'] : 0
            );
        }
    }

    foreach ($grouped_events as $event_identifier => $data) {
        $course_id = isset($data['info']['course_id']) ? $data['info']['course_id'] : '';
        $existing_event = null;

        // NEU: PRIMÄR nach course_id suchen (robust gegen Namensänderungen)
        if (!empty($course_id)) {
            $args = array(
                'post_type' => 'event',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'meta_query' => array(
                    array(
                        'key' => '_event_course_id',
                        'value' => $course_id,
                        'compare' => '='
                    )
                )
            );
            $query = new WP_Query($args);

            if ($query->have_posts()) {
                $existing_event = $query->posts[0];

                // Titel aktualisieren falls geändert
                if ($existing_event->post_title !== $event_identifier) {
                    wp_update_post(array(
                        'ID' => $existing_event->ID,
                        'post_title' => $event_identifier
                    ));
                }
            }
        }

        // FALLBACK: Nach Titel suchen
        if (!$existing_event) {
            $existing_event = get_page_by_title($event_identifier, OBJECT, 'event');
        }

        if (!$existing_event) {
            $event_id = wp_insert_post([
                'post_title' => $event_identifier,
                'post_type' => 'event',
                'post_status' => 'publish',
            ]);
            update_post_meta($event_id, '_event_availability', $data['info']['trail_seats']);
        } else {
            $event_id = $existing_event->ID;
        }

        update_post_meta($event_id, '_event_headcoach', $data['info']['headcoach']);
        update_post_meta($event_id, '_event_headcoach_image_url', $data['info']['headcoach_image_url']);
        update_post_meta($event_id, '_event_start_time', date('H:i', strtotime($data['info']['start_time'])));
        update_post_meta($event_id, '_event_end_time', date('H:i', strtotime($data['info']['end_time'])));
        update_post_meta($event_id, '_event_dates', $data['dates']);
        update_post_meta($event_id, '_event_location_original', $event_identifier);
        update_post_meta($event_id, '_event_venue', $data['info']['venue']);
        update_post_meta($event_id, '_event_price', $data['info']['price']);
        update_post_meta($event_id, '_manual_event', false);
        update_post_meta($event_id, '_event_headcoach_phone', $data['info']['headcoach_phone']);
        update_post_meta($event_id, '_event_headcoach_email', $data['info']['headcoach_email']);
        update_post_meta($event_id, '_event_venue_lat', isset($data['info']['venue_location']['lat']) ? $data['info']['venue_location']['lat'] : '');
        update_post_meta($event_id, '_event_venue_lng', isset($data['info']['venue_location']['lng']) ? $data['info']['venue_location']['lng'] : '');
        update_post_meta($event_id, '_event_whatsapp_link', $data['info']['whatsapp_link']);
        update_post_meta($event_id, '_event_region', $data['info']['region']);
        update_post_meta($event_id, '_event_description', $data['info']['description']);
        update_post_meta($event_id, '_event_is_workshop', isset($data['info']['is_workshop']) ? (int) $data['info']['is_workshop'] : 0);
        update_post_meta($event_id, '_event_is_course', isset($data['info']['is_course']) ? (int) $data['info']['is_course'] : 0);

        // NEU: course_id für robuste Vertragstyp-Zuordnung (unabhängig von Namensänderungen)
        if (isset($data['info']['course_id'])) {
            update_post_meta($event_id, '_event_course_id', $data['info']['course_id']);
        }

        if (!empty($data['info']['coaches'])) {
            update_post_meta($event_id, '_event_coaches', $data['info']['coaches']);
        }

        // Auto-Kategorisierung: Workshop / Kurs als Kinder von "angebot" zuweisen
        custom_events_sync_event_category($event_id, $data['info']);
    }

    error_log('Events successfully imported, triggering product sync...');
    custom_events_log_import('success', 'Manueller Import abgeschlossen', [
        'events_from_api' => count(json_decode(wp_remote_retrieve_body($response), true) ?: []),
    ]);
    wp_redirect(admin_url('edit.php?post_type=event&import_status=success&sync_products=1'));
    exit;
}
add_action('admin_post_import_events', 'import_events_from_api');

function add_import_button_to_event_page() {
    $screen = get_current_screen();
    if ($screen->post_type === 'event' && $screen->base === 'edit') {
        $selected_regions = get_option('event_selected_regions', array());
        $import_url = wp_nonce_url(admin_url('admin-post.php?action=import_events'), 'import_events_nonce');
        $delete_url = wp_nonce_url(admin_url('admin-post.php?action=delete_all_events'), 'delete_all_events_nonce');

        echo '<div id="import-events-container" style="display: inline-block; position: relative;">
                <a href="' . esc_url($import_url) . '" class="page-title-action" id="import-events-button">Events importieren</a>
                <a href="' . esc_url($delete_url) . '" class="page-title-action" id="delete-events-button" style="margin-left: 10px; background: #dc3545; color: white;" onclick="return confirm(\'Wirklich alle Events löschen?\');">Alle Events löschen</a>
                <span id="loading-indicator" style="display: none; margin-left: 10px;">⏳ Import läuft...</span>
              </div>';

        if (empty($selected_regions)) {
            echo '<div class="notice notice-warning"><p>Bitte wählen Sie zuerst die gewünschten Regionen in den <a href="' . admin_url('edit.php?post_type=event&page=event-settings') . '">Event-Einstellungen</a> aus.</p></div>';
        }

        if (isset($_GET['import_status'])) {
            if ($_GET['import_status'] === 'success') {
                echo '<div class="notice notice-success is-dismissible"><p>Events wurden erfolgreich importiert.</p></div>';
            } elseif ($_GET['import_status'] === 'failed') {
                echo '<div class="notice notice-error is-dismissible"><p>Fehler beim Import der Events. Bitte überprüfe die API-Verbindung.</p></div>';
            }
        }

        if (isset($_GET['delete_status']) && $_GET['delete_status'] === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p>Alle Events wurden erfolgreich gelöscht.</p></div>';
        }
    }
}
add_action('admin_notices', 'add_import_button_to_event_page');

function delete_all_events() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }

    check_admin_referer('delete_all_events_nonce');

    $args = array(
        'post_type' => 'event',
        'posts_per_page' => -1,
        'post_status' => 'any'
    );

    $events = get_posts($args);

    foreach ($events as $event) {
        wp_delete_post($event->ID, true);
    }

    wp_redirect(admin_url('edit.php?post_type=event&delete_status=success'));
    exit;
}
add_action('admin_post_delete_all_events', 'delete_all_events');

function custom_admin_scripts() {
    ?>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        const importButton = document.getElementById('import-events-button');
        const loadingIndicator = document.getElementById('loading-indicator');
        const syncProgress = document.createElement('div');
        syncProgress.style.display = 'none';
        syncProgress.className = 'notice notice-info';
        document.querySelector('.wrap').insertBefore(syncProgress, document.querySelector('.wrap').firstChild);

        if (importButton) {
            importButton.addEventListener('click', function() {
                loadingIndicator.style.display = 'inline';
            });
        }

        function startProductSync() {
            syncProgress.style.display = 'block';
            syncProgress.innerHTML = '<p>Produkte werden erstellt... <span id="sync-status">0%</span></p>' +
                                     '<div class="progress-bar" style="background: #f0f0f0; height: 20px; border: 1px solid #ccc;">' +
                                     '<div id="progress" style="background: #2271b1; width: 0%; height: 100%; transition: width 0.3s;"></div></div>';
            syncProducts(0);
        }

        function syncProducts(offset) {
            const data = {
                action: 'sync_event_products',
                nonce: '<?php echo wp_create_nonce("sync_products_nonce"); ?>',
                offset: offset
            };

            jQuery.post(ajaxurl, data, function(response) {
                if (response.success) {
                    const progress = Math.round((response.processed / response.total) * 100);
                    document.getElementById('progress').style.width = progress + '%';
                    document.getElementById('sync-status').textContent = progress + '%';

                    if (!response.done) {
                        setTimeout(() => syncProducts(response.next_offset), 1000);
                    } else {
                        syncProgress.innerHTML = '<p>✅ Produkte wurden erstellt</p>';
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    }
                }
            }).fail(function() {
                syncProgress.innerHTML = '<p class="notice notice-error">❌ Fehler bei der Produkt-Erstellung. <a href="#" onclick="startProductSync(); return false;">Erneut versuchen</a></p>';
            });
        }

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('sync_products') === '1') {
            startProductSync();
        }
    });
    </script>
    <?php
}
add_action('admin_footer', 'custom_admin_scripts');

/**
 * Synchronisiert die event_category Taxonomy basierend auf API-Flags is_workshop / is_course.
 * Terms werden als Kinder von "angebot" erstellt/zugewiesen, damit die Status-Erkennung funktioniert.
 */
function custom_events_sync_event_category($event_id, $event_info) {
    $angebot_term = get_term_by('slug', 'angebot', 'event_category');
    if (!$angebot_term) {
        // "angebot" Parent-Kategorie existiert nicht — Sync nicht möglich
        return;
    }

    $is_workshop = !empty($event_info['is_workshop']);
    $is_course   = !empty($event_info['is_course']);

    // Workshop-Term verwalten
    $workshop_term = get_term_by('slug', 'workshop', 'event_category');
    if ($is_workshop) {
        if (!$workshop_term) {
            $result = wp_insert_term('Workshop', 'event_category', [
                'parent' => $angebot_term->term_id,
                'slug'   => 'workshop',
            ]);
            $workshop_id = is_array($result) ? $result['term_id'] : 0;
        } else {
            $workshop_id = $workshop_term->term_id;
        }
        if ($workshop_id) {
            wp_set_object_terms($event_id, [(int) $workshop_id], 'event_category', true);
        }
    } elseif ($workshop_term) {
        wp_remove_object_terms($event_id, $workshop_term->term_id, 'event_category');
    }

    // Kurs-Term verwalten
    $kurs_term = get_term_by('slug', 'kurs', 'event_category');
    if ($is_course) {
        if (!$kurs_term) {
            $result = wp_insert_term('Kurs', 'event_category', [
                'parent' => $angebot_term->term_id,
                'slug'   => 'kurs',
            ]);
            $kurs_id = is_array($result) ? $result['term_id'] : 0;
        } else {
            $kurs_id = $kurs_term->term_id;
        }
        if ($kurs_id) {
            wp_set_object_terms($event_id, [(int) $kurs_id], 'event_category', true);
        }
    } elseif ($kurs_term) {
        wp_remove_object_terms($event_id, $kurs_term->term_id, 'event_category');
    }
}
?>
