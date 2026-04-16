<?php
if (!defined('ABSPATH')) {
    exit;
}

class Event_Regions_Handler {
    public function __construct() {
        // Menü-Registrierung erfolgt über Event_Admin_Page (kombinierte Seite)
    }

    public function get_available_regions() {
        if (!defined('EVENT_API_TOKEN')) {
            return array();
        }

        $args = array(
            'timeout' => 60,
            'sslverify' => true
        );

        $api_url = 'https://academyboard.parkourone.com/api/event/dates?token=' . EVENT_API_TOKEN;
        $response = wp_remote_get($api_url, $args);

        if (is_wp_error($response)) {
            return array();
        }

        $events = json_decode(wp_remote_retrieve_body($response), true);
        $regions = array();

        foreach ($events as $event) {
            if (!empty($event['region']) && !in_array($event['region'], $regions)) {
                $regions[] = $event['region'];
            }
        }

        return array_unique($regions);
    }

    public function delete_events_not_in_selected_regions($selected_regions) {
      $args = array(
          'post_type' => 'event',
          'posts_per_page' => -1,
          'post_status' => 'publish',
          'meta_query' => array(
              array(
                  'key' => '_event_region',
                  'value' => $selected_regions,
                  'compare' => 'NOT IN'
              )
          )
      );

      $events = get_posts($args);

      foreach ($events as $event) {
          wp_delete_post($event->ID, true);
      }
  }

    public function add_event_settings_page() {
        add_submenu_page(
            'parkourone',
            'Event Einstellungen',
            'Event Einstellungen',
            'manage_options',
            'event-settings',
            array($this, 'render_event_settings_page')
        );
    }

    public function render_event_settings_page() {
        if (isset($_POST['save_event_settings']) && check_admin_referer('save_event_settings')) {
            $selected_regions = isset($_POST['selected_regions']) ? $_POST['selected_regions'] : array();
            update_option('event_selected_regions', $selected_regions);
            $this->delete_events_not_in_selected_regions($selected_regions);

            echo '<div class="notice notice-success"><p>Einstellungen gespeichert!</p></div>';
        }

        $regions = $this->get_available_regions();
        $selected_regions = get_option('event_selected_regions', array());
        ?>
        <div class="wrap">
            <h1>Event Einstellungen</h1>
            <form method="post" action="">
                <?php wp_nonce_field('save_event_settings'); ?>
                <h2>Verfügbare Regionen</h2>
                <p>Wählen Sie die Regionen aus, deren Events importiert werden sollen:</p>
                <?php foreach ($regions as $region): ?>
                    <label style="display:block;margin-bottom:10px;">
                        <input type="checkbox"
                               name="selected_regions[]"
                               value="<?php echo esc_attr($region); ?>"
                               <?php checked(in_array($region, $selected_regions)); ?>>
                        <?php echo esc_html($region); ?>
                    </label>
                <?php endforeach; ?>
                <p>
                    <input type="submit"
                           name="save_event_settings"
                           class="button button-primary"
                           value="Einstellungen speichern">
                </p>
            </form>
        </div>
        <?php
    }

    public function filter_events_by_region($events) {
        $selected_regions = get_option('event_selected_regions', array());
        if (empty($selected_regions)) {
            return array();
        }

        return array_filter($events, function($event_data) use ($selected_regions) {
            return !empty($event_data['region']) && in_array($event_data['region'], $selected_regions);
        });
    }
}
?>
