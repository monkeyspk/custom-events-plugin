<?php
/**
 * Event Term Reassignment
 *
 * Fängt das Löschen von event_category Terms ab und bietet
 * die Möglichkeit, zugeordnete Events in eine andere Kategorie zu verschieben.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Event_Term_Reassignment {

    public function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'maybe_enqueue_scripts']);
        add_action('wp_ajax_get_term_event_count', [$this, 'ajax_get_term_event_count']);
        add_action('wp_ajax_get_sibling_terms', [$this, 'ajax_get_sibling_terms']);
        add_action('wp_ajax_reassign_term_events', [$this, 'ajax_reassign_term_events']);
    }

    /**
     * Lädt JS/CSS nur auf edit-tags.php für event_category
     */
    public function maybe_enqueue_scripts($hook) {
        $screen = get_current_screen();
        if (!$screen || $screen->taxonomy !== 'event_category' || $screen->base !== 'edit-tags') {
            return;
        }

        wp_enqueue_style(
            'term-reassignment-styles',
            plugin_dir_url(dirname(__FILE__)) . 'assets/css/term-reassignment.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'term-reassignment-script',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/term-reassignment.js',
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('term-reassignment-script', 'termReassignmentL10n', [
            'modalTitle'      => 'Kategorie löschen',
            'noEvents'        => 'Dieser Kategorie sind keine Events zugewiesen.',
            'eventsAssigned'  => '%d Event(s) sind dieser Kategorie zugewiesen.',
            'reassignTo'      => 'Events verschieben nach:',
            'noReassignment'  => 'Keine Umkategorisierung',
            'sameLevelGroup'  => 'Gleiche Ebene',
            'otherGroup'      => 'Andere Kategorien',
            'confirmDelete'   => 'Löschen',
            'cancel'          => 'Abbrechen',
            'loading'         => 'Laden...',
            'reassigning'     => 'Events werden verschoben...',
            'error'           => 'Fehler',
        ]);

        wp_localize_script('term-reassignment-script', 'wpApiSettings', [
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Zählt Events für einen Term
     */
    public function ajax_get_term_event_count() {
        check_ajax_referer('wp_rest', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung.');
        }

        $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
        if (!$term_id) {
            wp_send_json_error('Ungültige Term-ID.');
        }

        $term = get_term($term_id, 'event_category');
        if (!$term || is_wp_error($term)) {
            wp_send_json_error('Kategorie nicht gefunden.');
        }

        wp_send_json_success([
            'count'     => (int) $term->count,
            'term_name' => $term->name,
        ]);
    }

    /**
     * Liefert alle anderen Terms als Dropdown-Optionen (hierarchisch mit Prefix)
     */
    public function ajax_get_sibling_terms() {
        check_ajax_referer('wp_rest', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung.');
        }

        $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
        if (!$term_id) {
            wp_send_json_error('Ungültige Term-ID.');
        }

        $term = get_term($term_id, 'event_category');
        if (!$term || is_wp_error($term)) {
            wp_send_json_error('Kategorie nicht gefunden.');
        }

        $all_options = $this->get_term_options('event_category', $term_id);

        $siblings = [];
        $others   = [];

        foreach ($all_options as $item) {
            if ($item['original_parent'] === (int) $term->parent) {
                $siblings[] = [
                    'id'    => $item['id'],
                    'name'  => $item['name'],
                    'count' => $item['count'],
                ];
            } else {
                $others[] = [
                    'id'    => $item['id'],
                    'name'  => $item['name'],
                    'count' => $item['count'],
                ];
            }
        }

        wp_send_json_success([
            'siblings' => $siblings,
            'others'   => $others,
        ]);
    }

    /**
     * Verschiebt Events via wp_set_object_terms (append) + wp_remove_object_terms
     */
    public function ajax_reassign_term_events() {
        check_ajax_referer('wp_rest', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Keine Berechtigung.');
        }

        $term_id        = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
        $target_term_id = isset($_POST['target_term_id']) ? intval($_POST['target_term_id']) : 0;

        if (!$term_id) {
            wp_send_json_error('Ungültige Term-ID.');
        }

        $events = get_posts([
            'post_type'      => 'event',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'tax_query'      => [
                [
                    'taxonomy' => 'event_category',
                    'field'    => 'term_id',
                    'terms'    => [$term_id],
                ],
            ],
        ]);

        $reassigned = 0;

        foreach ($events as $event) {
            if ($target_term_id > 0) {
                wp_set_object_terms($event->ID, [$target_term_id], 'event_category', true);
            }
            wp_remove_object_terms($event->ID, $term_id, 'event_category');
            $reassigned++;
        }

        wp_send_json_success([
            'reassigned' => $reassigned,
        ]);
    }

    /**
     * Baut eine hierarchisch sortierte Liste aller Terms (außer $exclude_id)
     */
    private function get_term_options($taxonomy, $exclude_id) {
        $all_terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'exclude'    => [$exclude_id],
        ]);

        if (is_wp_error($all_terms) || empty($all_terms)) {
            return [];
        }

        // Pass 1: Index
        $by_id = [];
        foreach ($all_terms as $t) {
            $by_id[$t->term_id] = $t;
        }

        // Pass 2: Parent→Children Map
        $children_map = [];
        foreach ($all_terms as $t) {
            $parent = (int) $t->parent;
            // Kinder des gelöschten Terms werden zu Root
            if ($parent === $exclude_id) {
                $parent = 0;
            }
            // Parent existiert nicht in unserer Liste → Root
            if ($parent !== 0 && !isset($by_id[$parent])) {
                $parent = 0;
            }
            if (!isset($children_map[$parent])) {
                $children_map[$parent] = [];
            }
            $children_map[$parent][] = $t->term_id;
        }

        // Pass 3: Rekursiv flattenen
        $result = [];
        $this->walk_tree($by_id, $children_map, 0, 0, $result);
        return $result;
    }

    /**
     * Rekursiver Tree-Walker für hierarchische Term-Liste
     */
    private function walk_tree(&$by_id, &$children_map, $parent, $depth, &$result) {
        if (!isset($children_map[$parent])) {
            return;
        }
        foreach ($children_map[$parent] as $tid) {
            $t = $by_id[$tid];
            $prefix = str_repeat('— ', $depth);
            $result[] = [
                'id'              => (int) $t->term_id,
                'name'            => $prefix . $t->name,
                'count'           => (int) $t->count,
                'original_parent' => (int) $t->parent,
            ];
            $this->walk_tree($by_id, $children_map, $tid, $depth + 1, $result);
        }
    }
}
