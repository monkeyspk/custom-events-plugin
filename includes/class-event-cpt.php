<?php
class Event_CPT {
    public function __construct() {
        add_action('init', array($this, 'register_event_post_type'));
    }

    public function register_event_post_type() {
        $labels = array(
            'name' => 'Klassen',
            'singular_name' => 'Klasse',
            'add_new' => 'Neue Klasse',
            'all_items' => 'Alle Klassen',
            'add_new_item' => 'Neue Klasse hinzufügen',
            'edit_item' => 'Klasse bearbeiten',
            'new_item' => 'Neue Klasse',
            'view_item' => 'Klasse ansehen',
            'search_items' => 'Klassen suchen',
            'not_found' => 'Keine Klassen gefunden',
            'not_found_in_trash' => 'Keine Klassen im Papierkorb',
            'parent_item_colon' => 'Übergeordnete Klasse'
        );

        $args = array(
            'labels' => $labels,
            'public' => true,
            'has_archive' => true,
            'show_in_menu' => 'parkourone',
            'rewrite' => array( 'slug' => 'events' ),
            'show_in_rest' => true,
            'supports' => array( 'title', 'editor', 'thumbnail' ),
            'taxonomies' => array( 'category' ),
        );

        register_post_type( 'event', $args );
    }
}

add_filter('manage_event_posts_columns', 'add_event_id_column');
function add_event_id_column($columns) {
    $columns['event_id'] = __('ID');
    return $columns;
}

add_action('manage_event_posts_custom_column', 'show_event_id_column', 10, 2);
function show_event_id_column($column, $post_id) {
    if ($column === 'event_id') {
        echo '(' . $post_id . ')';
    }
}

/**
 * GLOBALE Warnung anzeigen wenn Events keine Kategorie zugewiesen haben
 */
add_action('admin_notices', 'show_events_without_category_warning');
function show_events_without_category_warning() {
    // Alle publizierten Events holen
    $all_events = get_posts(array(
        'post_type' => 'event',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    ));

    $events_without_category = array();

    foreach ($all_events as $event) {
        $categories = wp_get_post_terms($event->ID, 'event_category');

        // Wenn keine Kategorien oder Fehler
        if (empty($categories) || is_wp_error($categories)) {
            $events_without_category[] = $event;
        }
    }

    if (!empty($events_without_category)) {
        $count = count($events_without_category);
        $event_links = array();

        foreach (array_slice($events_without_category, 0, 5) as $event) {
            $edit_link = get_edit_post_link($event->ID);
            $event_links[] = '<a href="' . esc_url($edit_link) . '">' . esc_html($event->post_title) . '</a>';
        }

        $more_text = '';
        if ($count > 5) {
            $more_text = ' ... <a href="' . admin_url('edit.php?post_type=event') . '"><strong>und ' . ($count - 5) . ' weitere</strong></a>';
        }

        echo '<div class="notice notice-error">';
        echo '<p><strong>🚨 ACHTUNG: ' . $count . ' Klasse(n) ohne Kategorie!</strong> ';
        echo 'Bitte sofort zuweisen: ' . implode(', ', $event_links) . $more_text . '</p>';
        echo '</div>';
    }
}
?>
