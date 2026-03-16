<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kombinierte Event-Admin-Seite mit Tabs
 * Fasst Event-Einstellungen und Umbuchungen zusammen
 */
class Event_Admin_Page {

    private static $regions_handler;
    private static $rebooking_manager;

    public static function init($regions_handler, $rebooking_manager) {
        self::$regions_handler = $regions_handler;
        self::$rebooking_manager = $rebooking_manager;

        add_action('admin_menu', [__CLASS__, 'add_menu_page']);
    }

    public static function add_menu_page() {
        add_submenu_page(
            'parkourone',
            'Event Verwaltung',
            'Event Verwaltung',
            'manage_options',
            'event-admin',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'einstellungen';
        $tabs = [
            'einstellungen' => 'Einstellungen',
            'umbuchungen'   => 'Umbuchungen',
        ];
        ?>
        <div class="wrap">
            <h1>Event Verwaltung</h1>
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_key => $tab_label): ?>
                    <a href="<?php echo esc_url(add_query_arg('tab', $tab_key, admin_url('admin.php?page=event-admin'))); ?>"
                       class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="ab-tab-content" style="margin-top: 20px;">
                <?php
                switch ($current_tab) {
                    case 'umbuchungen':
                        self::render_tab_content(function() {
                            self::$rebooking_manager->render_rebooking_page();
                        });
                        break;
                    default:
                        self::render_tab_content(function() {
                            self::$regions_handler->render_event_settings_page();
                        });
                        break;
                }
                ?>
            </div>
        </div>
        <style>
            .ab-tab-content > .wrap { padding: 0; margin: 0; }
            .ab-tab-content > .wrap > h1:first-child { display: none; }
        </style>
        <?php
    }

    /**
     * Rendert den Tab-Inhalt und strippt den äußeren Wrapper
     */
    private static function render_tab_content($callback) {
        ob_start();
        $callback();
        $content = ob_get_clean();

        // Entferne den äußeren <div class="wrap"> Wrapper und den <h1> Titel
        $content = preg_replace('/^\s*<div class="wrap">\s*/i', '', $content, 1);
        $content = preg_replace('/<h1[^>]*>.*?<\/h1>/is', '', $content, 1);
        // Entferne das letzte schließende </div>
        $content = preg_replace('/<\/div>\s*$/i', '', $content, 1);

        echo $content;
    }
}
