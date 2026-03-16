<?php
class Event_Metaboxes {
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_event_metaboxes'));
        add_action('save_post', array($this, 'save_event_metaboxes'));
    }

    public function add_event_metaboxes() {
        add_meta_box(
            'event_details',
            'Event Details',
            array($this, 'render_event_metabox'),
            'event',
            'normal',
            'high'
        );
    }

    public function render_event_metabox($post) {
        wp_nonce_field(basename(__FILE__), 'event_nonce');

        // ID und Permalink
        echo '<p><label for="event_id">Event ID:</label><br />';
        echo '<input type="text" id="event_id" name="event_id" value="' . esc_attr($post->ID) . '" readonly /></p>';

        $permalink = get_post_meta($post->ID, '_event_permalink', true);
        if (!$permalink) {
            $permalink = sanitize_title(get_the_title($post->ID));
        }
        echo '<p><label for="event_permalink">Event Permalink:</label><br />';
        echo '<input type="text" id="event_permalink" name="event_permalink" value="' . esc_attr($permalink) . '" readonly /></p>';

        // Course-ID (AcademyBoard) - readonly Anzeige
        $course_id = get_post_meta($post->ID, '_event_course_id', true);
        echo '<p><label for="event_course_id">Course-ID (AcademyBoard):</label><br />';
        echo '<input type="text" id="event_course_id" name="event_course_id" value="' . esc_attr($course_id ? $course_id : 'Noch nicht importiert') . '" readonly style="background: #f0f0f0;" /></p>';

        // Werte abrufen
        $event_dates = get_post_meta($post->ID, '_event_dates', true);
        $start_time = get_post_meta($post->ID, '_event_start_time', true);
        $end_time = get_post_meta($post->ID, '_event_end_time', true);
        $headcoach = get_post_meta($post->ID, '_event_headcoach', true);
        $headcoach_email = get_post_meta($post->ID, '_event_headcoach_email', true);
        // Hier: Verwende den neuen Schlüssel _event_venue statt _event_location
        $venue = get_post_meta($post->ID, '_event_venue', true);
        $venue_lat = get_post_meta($post->ID, '_event_venue_lat', true);
        $venue_lng = get_post_meta($post->ID, '_event_venue_lng', true);
        $availability = get_post_meta($post->ID, '_event_availability', true);
        $price = get_post_meta($post->ID, '_event_price', true);
        $headcoach_image_url = get_post_meta($post->ID, '_event_headcoach_image_url', true);
        $headcoach_phone = get_post_meta($post->ID, '_event_headcoach_phone', true);
        $manual_event = get_post_meta($post->ID, '_manual_event', true);
        $has_end_date = get_post_meta($post->ID, '_event_has_end_date', true);
        $repeat_rate = get_post_meta($post->ID, '_event_repeat_rate', true);
        $start_date = get_post_meta($post->ID, '_event_start_date', true);
        $end_date = get_post_meta($post->ID, '_event_end_date', true);

        // Grundinformationen
        echo '<div class="event-basic-info">';
        echo '<h3>Grundinformationen</h3>';

        echo '<p><label for="event_start_date">Start Date:</label><br />';
        echo '<input type="date" id="event_start_date" name="event_start_date" value="' . esc_attr($start_date) . '" /></p>';

        echo '<p><label for="event_has_end_date">Does the event have an end date?</label><br />';
        echo '<input type="checkbox" id="event_has_end_date" name="event_has_end_date" ' . checked($has_end_date, 'on', false) . ' /></p>';

        echo '<div id="end_date_container" style="display:' . ($has_end_date ? 'block' : 'none') . ';">';
        echo '<p><label for="event_end_date">End Date:</label><br />';
        echo '<input type="date" id="event_end_date" name="event_end_date" value="' . esc_attr($end_date) . '" /></p>';
        echo '</div>';

        echo '<p><label for="event_repeat_rate">Repeat Rate:</label><br />';
        echo '<select id="event_repeat_rate" name="event_repeat_rate">
                <option value="none"' . selected($repeat_rate, 'none', false) . '>None</option>
                <option value="daily"' . selected($repeat_rate, 'daily', false) . '>Daily</option>
                <option value="weekly"' . selected($repeat_rate, 'weekly', false) . '>Weekly</option>
                <option value="monthly"' . selected($repeat_rate, 'monthly', false) . '>Monthly</option>
              </select></p>';

        echo '<p><label for="event_start_time">Start Time:</label><br />';
        echo '<input type="time" id="event_start_time" name="event_start_time" value="' . esc_attr($start_time) . '" /></p>';

        echo '<p><label for="event_end_time">End Time:</label><br />';
        echo '<input type="time" id="event_end_time" name="event_end_time" value="' . esc_attr($end_time) . '" /></p>';

        echo '<p><label for="event_headcoach">Headcoach:</label><br />';
        echo '<input type="text" id="event_headcoach" name="event_headcoach" value="' . esc_attr($headcoach) . '" /></p>';

        echo '<p><label for="event_headcoach_phone">Headcoach Phone:</label><br />';
        echo '<input type="text" id="event_headcoach_phone" name="event_headcoach_phone" value="' . esc_attr($headcoach_phone) . '" /></p>';

        echo '<p><label for="event_headcoach_email">Headcoach Email:</label><br />';
        echo '<input type="email" id="event_headcoach_email" name="event_headcoach_email" value="' . esc_attr($headcoach_email) . '" /></p>';

        echo '<p><label for="event_venue">Treffpunkt:</label><br />';
        echo '<input type="text" id="event_venue" name="event_venue" value="' . esc_attr($venue) . '" /></p>';

        echo '<p><label for="event_venue_lat">Breitengrad (Lat):</label><br />';
        echo '<input type="text" id="event_venue_lat" name="event_venue_lat" value="' . esc_attr($venue_lat) . '" /></p>';

        echo '<p><label for="event_venue_lng">Längengrad (Lng):</label><br />';
        echo '<input type="text" id="event_venue_lng" name="event_venue_lng" value="' . esc_attr($venue_lng) . '" /></p>';

        echo '<p><label for="event_availability">Verfügbare Plätze:</label><br />';
        echo '<input type="number" id="event_availability" name="event_availability" value="' . esc_attr($availability) . '" /></p>';

        echo '<p><label for="event_price">Preis:</label><br />';
        echo '<input type="number" step="0.01" id="event_price" name="event_price" value="' . esc_attr($price) . '" /></p>';

        echo '<p><label for="event_headcoach_image_url">Headcoach Bild URL:</label><br />';
        echo '<input type="text" id="event_headcoach_image_url" name="event_headcoach_image_url" value="' . esc_attr($headcoach_image_url) . '" style="width:100%;" /></p>';
        echo '</div>';

        echo '<p><label for="event_whatsapp_link">WhatsApp Link:</label><br />';
        echo '<input type="text" id="event_whatsapp_link" name="event_whatsapp_link" value="' . esc_attr(get_post_meta($post->ID, '_event_whatsapp_link', true)) . '" /></p>';

        echo '<p><label for="event_region">Region:</label><br />';
        echo '<input type="text" id="event_region" name="event_region" value="' . esc_attr(get_post_meta($post->ID, '_event_region', true)) . '" /></p>';

        echo '<p><label for="event_description">Beschreibung:</label><br />';
        echo '<textarea id="event_description" name="event_description" rows="4" style="width:100%;">' . esc_textarea(get_post_meta($post->ID, '_event_description', true)) . '</textarea></p>';

        echo '<p><label for="event_dropdown_info"><strong>Dropdown Info:</strong></label><br />';
        echo '<textarea id="event_dropdown_info" name="event_dropdown_info" rows="2" style="width:100%;" placeholder="Diese Information wird im Dropdown angezeigt">' . esc_textarea(get_post_meta($post->ID, '_event_dropdown_info', true)) . '</textarea></p>';

        // Termine anzeigen
        echo '<div class="event-dates">';
        echo '<h3>Termine</h3>';
        echo '<div id="event_dates_display" style="margin-bottom: 20px;">';
        if (!empty($event_dates)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>Datum</th>';
            echo '<th>Treffpunkt</th>';
            echo '<th>Koordinaten</th>';
            echo '<th>Probe-Plätze</th>';
            echo '<th>Verfügbare Plätze</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            foreach ($event_dates as $date_info) {
                echo '<tr>';
                echo '<td>' . esc_html($date_info['date']) . '</td>';
                echo '<td>' . esc_html($date_info['venue']) . '</td>';
                echo '<td>' . esc_html($date_info['venue_lat']) . ', ' . esc_html($date_info['venue_lng']) . '</td>';
                echo '<td>' . (isset($date_info['trail_seats']) ? esc_html($date_info['trail_seats']) : '-') . '</td>';
                echo '<td>' . (isset($date_info['available_seats']) ? esc_html($date_info['available_seats']) : '-') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>Keine Termine verfügbar</p>';
        }
        echo '</div>';
        echo '</div>';

        echo '<p><label for="manual_event">Manuelles Event:</label><br />';
        echo '<input type="checkbox" id="manual_event" name="manual_event" ' . checked($manual_event, true, false) . ' disabled />';
        echo '<span> Wird automatisch für manuell erstellte Events markiert</span></p>';

        echo '<script>
            document.getElementById("event_has_end_date").addEventListener("change", function() {
                var endDateContainer = document.getElementById("end_date_container");
                endDateContainer.style.display = this.checked ? "block" : "none";
            });
        </script>';
    }

    public function save_event_metaboxes($post_id) {
        if (!isset($_POST['event_nonce']) || !wp_verify_nonce($_POST['event_nonce'], basename(__FILE__))) {
            return $post_id;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post_id;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return $post_id;
        }

        if (isset($_POST['event_dates']) && is_array($_POST['event_dates'])) {
       $dates = array();
       foreach ($_POST['event_dates'] as $date_entry) {
           if (!empty($date_entry['date'])) {
               $dates[] = array(
                   'date' => sanitize_text_field($date_entry['date']),
                   'venue' => sanitize_text_field($date_entry['venue']),
                   'venue_lat' => sanitize_text_field($date_entry['venue_lat']),
                   'venue_lng' => sanitize_text_field($date_entry['venue_lng'])
               );
           }
       }
       update_post_meta($post_id, '_event_dates', $dates);
   }

        $fields = array(
            '_event_start_date' => 'sanitize_text_field',
            '_event_end_date' => 'sanitize_text_field',
            '_event_repeat_rate' => 'sanitize_text_field',
            '_event_start_time' => 'sanitize_text_field',
            '_event_end_time' => 'sanitize_text_field',
            '_event_headcoach' => 'sanitize_text_field',
            '_event_venue' => 'sanitize_text_field',  // Neuer Schlüssel für Treffpunkt
            '_event_price' => 'floatval',
            '_event_headcoach_image_url' => 'esc_url_raw',
            '_event_headcoach_phone' => 'sanitize_text_field',
            '_event_headcoach_email' => 'sanitize_email',  // Neue Zeile
            '_event_venue_lat' => 'sanitize_text_field',
            '_event_venue_lng' => 'sanitize_text_field',
            '_event_whatsapp_link' => 'esc_url_raw',
            '_event_region' => 'sanitize_text_field',
            '_event_description' => 'wp_kses_post',
            '_event_dropdown_info' => 'wp_kses_post'  // Add this line for the new field

        );

        if (!get_post_meta($post_id, '_event_availability', true)) {
            if (isset($_POST['event_availability'])) {
                update_post_meta($post_id, '_event_availability', intval($_POST['event_availability']));
            }
        }

        foreach ($fields as $meta_key => $sanitize_callback) {
            if (isset($_POST[ltrim($meta_key, '_')])) {
                $value = $_POST[ltrim($meta_key, '_')];
                update_post_meta($post_id, $meta_key, $sanitize_callback($value));
            }
        }

        update_post_meta($post_id, '_event_has_end_date', isset($_POST['event_has_end_date']) ? 'on' : 'off');

        if (!get_post_meta($post_id, '_manual_event', true)) {
            update_post_meta($post_id, '_manual_event', true);
        }

        if (!empty($_POST['post_title'])) {
            $permalink = sanitize_title($_POST['post_title']);
            update_post_meta($post_id, '_event_permalink', $permalink);
        }
    }
}
