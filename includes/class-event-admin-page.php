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
            'import-log'    => 'Import-Status',
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
                    case 'import-log':
                        self::render_import_log();
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
     * Import-Statusboard: Letzte 20 Imports mit Status anzeigen
     */
    private static function render_import_log() {
        $log = get_option('custom_events_import_log', []);

        // Nächster geplanter Cron
        $next_cron = '';
        if (function_exists('as_next_scheduled_action')) {
            $next_ts = as_next_scheduled_action('ab_bestandskunde_reminder_check');
        }
        $cron_next = wp_next_scheduled('custom_events_cron_import');
        ?>
        <h2>Import-Status</h2>

        <div style="background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:15px; margin-bottom:20px;">
            <h3 style="margin-top:0;">Letzte Imports</h3>
            <?php if (empty($log)): ?>
                <p style="color:#888;">Noch keine Imports protokolliert. Das Logging beginnt ab dem nächsten Import.</p>
            <?php else: ?>
                <table class="widefat striped" style="margin-top:10px;">
                    <thead>
                        <tr>
                            <th style="width:160px;">Zeitpunkt</th>
                            <th style="width:80px;">Status</th>
                            <th>Meldung</th>
                            <th style="width:120px;">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log as $entry): ?>
                            <tr>
                                <td><?php echo esc_html($entry['time']); ?></td>
                                <td>
                                    <?php
                                    $status = $entry['status'];
                                    if ($status === 'success') {
                                        echo '<span style="color:#46b450; font-weight:bold;">&#10003; OK</span>';
                                    } elseif ($status === 'error') {
                                        echo '<span style="color:#dc3232; font-weight:bold;">&#10007; Fehler</span>';
                                    } else {
                                        echo '<span style="color:#f0b849; font-weight:bold;">&#9679; Gestartet</span>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html($entry['message']); ?></td>
                                <td>
                                    <?php
                                    if (!empty($entry['details'])) {
                                        $parts = [];
                                        if (isset($entry['details']['events_from_api'])) {
                                            $parts[] = 'API: ' . $entry['details']['events_from_api'];
                                        }
                                        if (isset($entry['details']['events_imported'])) {
                                            $parts[] = 'Import: ' . $entry['details']['events_imported'];
                                        }
                                        echo esc_html(implode(' | ', $parts));
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div style="background:#f0f6fc; border:1px solid #c8d7e1; border-radius:4px; padding:15px;">
            <h3 style="margin-top:0;">Hinweise</h3>
            <ul style="list-style:disc; padding-left:20px; margin:0;">
                <li>Einträge mit Status <strong>"Gestartet"</strong> ohne anschliessendes "OK" deuten auf einen <strong>Timeout oder Absturz</strong> hin.</li>
                <li>Der Import wird über den externen Cron-Job ausgelöst (POST auf <code>/wp-json/events/v1/cron-import</code>).</li>
                <li>Bei Timeout-Problemen: Server PHP <code>max_execution_time</code> und <code>memory_limit</code> prüfen.</li>
                <li>Detaillierte Fehlermeldungen stehen im <code>debug.log</code> auf dem Server.</li>
            </ul>
        </div>
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
