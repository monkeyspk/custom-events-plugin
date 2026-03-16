<?php
class Event_Advanced_Filter {
    public function __construct() {
        add_shortcode('event_advanced_filter', array($this, 'render_advanced_filter'));
    }

    public function render_advanced_filter() {
        $output = '<div class="parkourone-advanced-filter">';
        $output .= $this->render_offer_filter();
        $output .= $this->render_age_filter();
        $output .= $this->render_location_filter();
        $output .= $this->render_weekday_filter();
        $output .= '</div>';
        return $output;
    }

    private function render_offer_filter() {
        $offer_parent = get_term_by('slug', 'angebot', 'event_category');
        $offer_categories = array();

        if ($offer_parent) {
            $offer_categories = get_terms([
                'taxonomy' => 'event_category',
                'hide_empty' => true,
                'parent' => $offer_parent->term_id
            ]);
        }

        $output = '<select id="offer-filter" class="parkourone-filter">';
        $output .= '<option value="">Angebot</option>';
        foreach ($offer_categories as $category) {
            $output .= sprintf('<option value="%s">%s</option>',
                esc_attr($category->slug),
                esc_html($category->name)
            );
        }
        $output .= '</select>';
        return $output;
    }

    private function render_age_filter() {
        $age_parent = get_term_by('slug', 'alter', 'event_category');
        $age_categories = array();

        if ($age_parent) {
            $age_categories = get_terms([
                'taxonomy' => 'event_category',
                'hide_empty' => true,
                'parent' => $age_parent->term_id
            ]);
        }

        $output = '<select id="age-filter" class="parkourone-filter">';
        $output .= '<option value="">Alter</option>';
        foreach ($age_categories as $category) {
            $output .= sprintf('<option value="%s">%s</option>',
                esc_attr($category->slug),
                esc_html($category->name)
            );
        }
        $output .= '</select>';
        return $output;
    }

    private function render_location_filter() {
        $location_parent = get_term_by('slug', 'ortschaft', 'event_category');
        $location_categories = array();

        if ($location_parent) {
            $location_categories = get_terms([
                'taxonomy' => 'event_category',
                'hide_empty' => true,
                'parent' => $location_parent->term_id
            ]);
        }

        $output = '<select id="location-filter" class="parkourone-filter">';
        $output .= '<option value="">Ortschaft</option>';
        foreach ($location_categories as $category) {
            $output .= sprintf('<option value="%s">%s</option>',
                esc_attr($category->slug),
                esc_html($category->name)
            );
        }
        $output .= '</select>';
        return $output;
    }

    private function render_weekday_filter() {
        $weekday_parent = get_term_by('slug', 'wochentag', 'event_category');
        $weekday_categories = array();

        if ($weekday_parent) {
            $weekday_categories = get_terms([
                'taxonomy' => 'event_category',
                'hide_empty' => true,
                'parent' => $weekday_parent->term_id
            ]);
        }

        $output = '<select id="weekday-filter" class="parkourone-filter">';
        $output .= '<option value="">Wochentag</option>';
        foreach ($weekday_categories as $category) {
            $output .= sprintf('<option value="%s">%s</option>',
                esc_attr($category->slug),
                esc_html($category->name)
            );
        }
        $output .= '</select>';
        return $output;
    }
}
?>
