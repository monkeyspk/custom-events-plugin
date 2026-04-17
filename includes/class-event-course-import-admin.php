<?php
if (!defined('ABSPATH')) {
    exit;
}

class Event_Course_Import_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu_page']);
        add_action('admin_post_run_course_import', [__CLASS__, 'handle_manual_import']);
    }

    public static function add_menu_page() {
        add_submenu_page(
            'parkourone',
            'Kurs/Workshop Import',
            'Kurs-Import Status',
            'manage_options',
            'course-import-status',
            [__CLASS__, 'render_page']
        );
    }

    public static function handle_manual_import() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', '', ['response' => 403]);
        }
        check_admin_referer('run_course_import_nonce');

        $result = Event_Course_Import::run();
        update_option('course_import_last_result', $result, false);
        update_option('course_import_last_run', current_time('mysql'), false);

        wp_redirect(admin_url('admin.php?page=course-import-status&imported=1'));
        exit;
    }

    public static function render_page() {
        $last_result = get_option('course_import_last_result', null);
        $last_run    = get_option('course_import_last_run', '');
        $import_log  = get_option('custom_events_import_log', []);

        // Bestehende Angebote zählen
        $angebote_count = wp_count_posts('angebot');
        $published = $angebote_count->publish ?? 0;
        $draft     = $angebote_count->draft ?? 0;

        // API-importierte Angebote
        $api_imported = new WP_Query([
            'post_type'      => 'angebot',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_query'     => [['key' => '_angebot_api_import', 'value' => '1']],
        ]);
        $api_count = $api_imported->found_posts;
        ?>
        <div class="wrap">
            <h1>Kurs/Workshop Import Status</h1>

            <?php if (isset($_GET['imported'])): ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Import wurde ausgeführt!</strong> Ergebnis siehe unten.</p>
            </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;">

                <!-- Status-Card -->
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;">
                    <h2 style="margin-top:0;">Übersicht</h2>
                    <table class="widefat striped" style="border:0;">
                        <tr>
                            <td><strong>Kurse & Workshops total</strong></td>
                            <td><?php echo intval($published); ?> publiziert, <?php echo intval($draft); ?> Entwürfe</td>
                        </tr>
                        <tr>
                            <td><strong>Davon via API importiert</strong></td>
                            <td><?php echo intval($api_count); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Letzter Import</strong></td>
                            <td><?php echo $last_run ? esc_html($last_run) : '<em>Noch nie ausgeführt</em>'; ?></td>
                        </tr>
                    </table>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px;">
                        <?php wp_nonce_field('run_course_import_nonce'); ?>
                        <input type="hidden" name="action" value="run_course_import">
                        <button type="submit" class="button button-primary button-large">
                            Kurs/Workshop Import jetzt ausführen
                        </button>
                        <p class="description">Ruft die AcademyBoard API ab und importiert Kurse/Workshops in den angebot CPT.</p>
                    </form>
                </div>

                <!-- Letztes Ergebnis -->
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;">
                    <h2 style="margin-top:0;">Letztes Import-Ergebnis</h2>
                    <?php if ($last_result): ?>
                    <table class="widefat striped" style="border:0;">
                        <tr>
                            <td><strong>Neu erstellt</strong></td>
                            <td><span style="color:#00a32a;font-weight:600;"><?php echo intval($last_result['imported']); ?></span></td>
                        </tr>
                        <tr>
                            <td><strong>Aktualisiert</strong></td>
                            <td><?php echo intval($last_result['updated']); ?></td>
                        </tr>
                        <?php if (!empty($last_result['errors'])): ?>
                        <tr>
                            <td><strong>Fehler</strong></td>
                            <td style="color:#d63638;">
                                <?php foreach ($last_result['errors'] as $err): ?>
                                    <?php echo esc_html($err); ?><br>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <?php if (!empty($last_result['log'])): ?>
                    <h3 style="margin-bottom:8px;">Import-Log</h3>
                    <div style="background:#f0f0f1;padding:12px;border-radius:4px;font-family:monospace;font-size:13px;">
                        <?php foreach ($last_result['log'] as $line): ?>
                            <?php echo esc_html($line); ?><br>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php else: ?>
                    <p><em>Noch kein Import ausgeführt.</em></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Importierte Angebote Liste -->
            <?php if ($api_imported->have_posts()): ?>
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin-top:20px;">
                <h2 style="margin-top:0;">Importierte Kurse & Workshops</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Titel</th>
                            <th>Kategorie</th>
                            <th>Buchungsart</th>
                            <th>Preis</th>
                            <th>Termine</th>
                            <th>Status</th>
                            <th>WC-Produkt</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($api_imported->have_posts()): $api_imported->the_post();
                        $pid = get_the_ID();
                        $terms = wp_get_post_terms($pid, 'angebot_kategorie', ['fields' => 'names']);
                        $buchung = get_post_meta($pid, '_angebot_buchungsart', true);
                        $preis = get_post_meta($pid, '_angebot_preis', true);
                        $termine = get_post_meta($pid, '_angebot_termine', true);
                        $produkt_id = get_post_meta($pid, '_angebot_ferienkurs_produkt_id', true);
                        $status = get_post_status();
                    ?>
                        <tr>
                            <td><a href="<?php echo get_edit_post_link($pid); ?>"><?php the_title(); ?></a></td>
                            <td><?php echo esc_html(implode(', ', $terms)); ?></td>
                            <td><?php echo esc_html($buchung ?: '–'); ?></td>
                            <td><?php echo esc_html($preis ?: '–'); ?></td>
                            <td><?php echo is_array($termine) ? count($termine) : 0; ?></td>
                            <td><?php echo $status === 'publish'
                                ? '<span style="color:#00a32a;">Publiziert</span>'
                                : '<span style="color:#dba617;">Entwurf</span>'; ?></td>
                            <td><?php echo $produkt_id
                                ? '<a href="' . get_edit_post_link($produkt_id) . '">#' . intval($produkt_id) . '</a>'
                                : '–'; ?></td>
                        </tr>
                    <?php endwhile; wp_reset_postdata(); ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Allgemeines Import-Log -->
            <?php if (!empty($import_log)): ?>
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin-top:20px;">
                <h2 style="margin-top:0;">Allgemeines Import-Log (letzte 10)</h2>
                <table class="widefat striped">
                    <thead>
                        <tr><th>Zeit</th><th>Status</th><th>Nachricht</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($import_log, 0, 10) as $entry): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo esc_html($entry['time']); ?></td>
                            <td><?php
                                $color = $entry['status'] === 'success' ? '#00a32a' : ($entry['status'] === 'error' ? '#d63638' : '#666');
                                echo '<span style="color:' . $color . ';font-weight:600;">' . esc_html($entry['status']) . '</span>';
                            ?></td>
                            <td><?php echo esc_html($entry['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
