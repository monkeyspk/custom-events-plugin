<?php
/**
 * Custom Events Plugin - GitHub Auto-Updater
 * Automatische Updates direkt von GitHub (gleches Pattern wie parkourone-theme)
 */

if (!defined('ABSPATH')) exit;

class Custom_Events_GitHub_Updater {

    private $github_repo = 'monkeyspk/custom-events-plugin';
    private $plugin_slug = 'custom-events-plugin';
    private $check_interval = 3600; // 1 Stunde in Sekunden
    private $transient_key = 'custom_events_github_update_check';
    private $last_error = null;

    public function __construct() {
        if (!is_admin()) {
            return;
        }

        if (!wp_doing_ajax()) {
            add_action('admin_init', [$this, 'maybe_auto_update']);
        }

        add_action('admin_init', [$this, 'handle_manual_check']);
        add_action('admin_notices', [$this, 'show_update_notice']);
    }

    /**
     * Rendert die Update-Sektion (wird vom Admin-Menü eingebettet)
     */
    public function render_admin_section() {
        $local_version = $this->get_local_version();
        $remote_version = $this->get_remote_version();
        $last_update = get_option($this->plugin_slug . '_last_update');
        $last_check = get_transient($this->transient_key);

        $is_up_to_date = ($local_version === $remote_version);
        $next_check_in = $last_check ? human_time_diff(time(), $last_check + $this->check_interval) : 'Jetzt';

        ?>
        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 600px; margin-top: 20px;">

            <h2 style="margin-top: 0;">Plugin Updates — Custom Events</h2>

            <?php
            if (isset($_GET['cev_updated']) && $_GET['cev_updated'] === '1') {
                $version = isset($_GET['cev_version']) ? sanitize_text_field($_GET['cev_version']) : '';
                echo '<div class="notice notice-success"><p><strong>Erfolg!</strong> Plugin auf Version <code>' . esc_html($version) . '</code> aktualisiert.</p></div>';
            } elseif (isset($_GET['cev_uptodate']) && $_GET['cev_uptodate'] === '1') {
                echo '<div class="notice notice-info"><p>Plugin ist bereits aktuell.</p></div>';
            } elseif (isset($_GET['cev_error'])) {
                echo '<div class="notice notice-error"><p><strong>Fehler:</strong> Update fehlgeschlagen. Siehe Error-Log.</p></div>';
            }
            ?>

            <table class="form-table" style="margin: 0;">
                <tr>
                    <th>Lokale Version:</th>
                    <td><code style="font-size: 14px;"><?php echo esc_html($local_version); ?></code></td>
                </tr>
                <tr>
                    <th>GitHub Version:</th>
                    <td>
                        <code style="font-size: 14px;"><?php echo esc_html($remote_version ?: 'Nicht erreichbar'); ?></code>
                        <?php if ($is_up_to_date && $remote_version): ?>
                            <span style="color: #46b450; margin-left: 10px;">&#10003; Aktuell</span>
                        <?php elseif ($remote_version): ?>
                            <span style="color: #dc3232; margin-left: 10px;">&#8593; Update verfügbar</span>
                        <?php endif; ?>
                        <?php if (!$remote_version && $this->last_error): ?>
                            <br><small style="color: #dc3232;">Fehler: <?php echo esc_html($this->last_error); ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Letztes Update:</th>
                    <td>
                        <?php if ($last_update): ?>
                            <?php echo esc_html($last_update['time']); ?>
                            <span style="color: #666;">(<?php echo human_time_diff(strtotime($last_update['time']), current_time('timestamp')); ?> her)</span>
                        <?php else: ?>
                            <span style="color: #666;">Noch kein Update</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Nächster Auto-Check:</th>
                    <td>in <?php echo esc_html($next_check_in); ?></td>
                </tr>
            </table>

            <hr style="margin: 20px 0;">

            <form method="post" style="display: inline;">
                <?php wp_nonce_field('cev_manual_update', 'cev_nonce'); ?>
                <button type="submit" name="cev_check_update" class="button button-primary" style="margin-right: 10px;">
                    Jetzt prüfen & aktualisieren
                </button>
            </form>

            <a href="https://github.com/<?php echo esc_attr($this->github_repo); ?>/commits/main" target="_blank" class="button">
                GitHub Commits ansehen
            </a>

            <p style="margin-top: 15px; color: #666; font-size: 13px;">
                Auto-Check jede Stunde. Repo: <code><?php echo esc_html($this->github_repo); ?></code>
            </p>

            <?php if (!$remote_version && $this->last_error): ?>
            <details style="font-size: 13px; color: #666; margin-top: 10px;">
                <summary style="cursor: pointer; font-weight: 600;">Debug-Informationen</summary>
                <div style="margin-top: 10px; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                    <p><strong>API URL:</strong> https://api.github.com/repos/<?php echo esc_html($this->github_repo); ?>/commits/main</p>
                    <p><strong>Fehler:</strong> <?php echo esc_html($this->last_error); ?></p>
                </div>
            </details>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Manuellen Update-Check verarbeiten
     */
    public function handle_manual_check() {
        if (!isset($_POST['cev_check_update'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['cev_nonce'] ?? '', 'cev_manual_update')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        delete_transient($this->transient_key);

        $remote_version = $this->get_remote_version();
        $local_version = $this->get_local_version();

        $redirect_args = ['page' => 'parkourone'];
        $was_updated = false;

        if ($remote_version && $remote_version !== $local_version) {
            $result = $this->do_update();
            if ($result) {
                $redirect_args['cev_updated'] = '1';
                $redirect_args['cev_version'] = $remote_version;
                $was_updated = true;
            } else {
                $redirect_args['cev_error'] = '1';
            }
        } else if (!$remote_version) {
            $redirect_args['cev_error'] = 'connection';
        } else {
            $redirect_args['cev_uptodate'] = '1';
        }

        set_transient($this->transient_key, time(), $this->check_interval);

        if ($was_updated) {
            set_transient('cev_update_success', $remote_version, 60);
            wp_redirect(admin_url('index.php'));
        } else {
            wp_redirect(add_query_arg($redirect_args, admin_url('admin.php')));
        }
        exit;
    }

    /**
     * Prüft und führt Auto-Update durch
     */
    public function maybe_auto_update() {
        $last_check = get_transient($this->transient_key);

        if ($last_check !== false) {
            return;
        }

        set_transient($this->transient_key, time(), $this->check_interval);

        $remote_version = $this->get_remote_version();

        if (!$remote_version) {
            return;
        }

        $local_version = $this->get_local_version();

        if ($remote_version !== $local_version) {
            $this->do_update();
        }
    }

    /**
     * Gibt die API-Headers zurück (inkl. Token falls gesetzt)
     */
    private function get_api_headers() {
        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/Custom-Events-Plugin-Updater'
        ];

        $token = get_option('parkourone_github_token', '');
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * Holt den neuesten Commit SHA von GitHub
     */
    private function get_remote_version() {
        $api_url = "https://api.github.com/repos/{$this->github_repo}/commits/main";
        $headers = $this->get_api_headers();

        $response = wp_remote_get($api_url, [
            'headers' => $headers,
            'timeout' => 20,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            $response = wp_remote_get($api_url, [
                'headers' => $headers,
                'timeout' => 20,
                'sslverify' => false
            ]);
        }

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            error_log('Custom Events Updater: ' . $this->last_error);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $this->last_error = 'HTTP ' . $response_code;
            error_log('Custom Events Updater: ' . $this->last_error);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['sha'])) {
            $this->last_error = null;
            return substr($body['sha'], 0, 7);
        }

        $this->last_error = 'Keine SHA in Antwort gefunden';
        return false;
    }

    private function get_local_version() {
        $version_file = $this->get_plugin_dir() . '.git-version';

        if (file_exists($version_file)) {
            return trim(file_get_contents($version_file));
        }

        return 'unknown';
    }

    private function save_local_version($version) {
        file_put_contents($this->get_plugin_dir() . '.git-version', $version);
    }

    private function get_plugin_dir() {
        return plugin_dir_path(dirname(__FILE__));
    }

    /**
     * Führt das Update durch
     */
    private function do_update() {
        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!WP_Filesystem(false, false, true)) {
            error_log('Custom Events Updater: WP_Filesystem konnte nicht initialisiert werden');
            return false;
        }

        $token = get_option('parkourone_github_token', '');
        if ($token) {
            // Für private Repos: GitHub API Zipball-Endpoint mit Auth
            $zip_url = "https://api.github.com/repos/{$this->github_repo}/zipball/main";
            $response = wp_remote_get($zip_url, [
                'headers' => $this->get_api_headers(),
                'timeout' => 60,
                'sslverify' => true,
                'stream' => true,
                'filename' => wp_tempnam('cev_update')
            ]);

            if (is_wp_error($response)) {
                error_log('Custom Events Updater: Download fehlgeschlagen - ' . $response->get_error_message());
                return false;
            }

            if (wp_remote_retrieve_response_code($response) !== 200) {
                error_log('Custom Events Updater: Download HTTP ' . wp_remote_retrieve_response_code($response));
                return false;
            }

            $temp_file = $response['filename'];
        } else {
            $zip_url = "https://github.com/{$this->github_repo}/archive/refs/heads/main.zip";
            $temp_file = download_url($zip_url);

            if (is_wp_error($temp_file)) {
                error_log('Custom Events Updater: Download fehlgeschlagen - ' . $temp_file->get_error_message());
                return false;
            }
        }

        $plugin_dir = $this->get_plugin_dir();
        $plugins_dir = dirname($plugin_dir);
        $temp_dir = $plugins_dir . '/' . $this->plugin_slug . '-temp-' . time();

        $unzip_result = unzip_file($temp_file, $temp_dir);

        if (is_wp_error($unzip_result)) {
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($temp_file) === true) {
                    @mkdir($temp_dir, 0755, true);
                    $zip->extractTo($temp_dir);
                    $zip->close();
                    $unzip_result = true;
                }
            }
        }

        @unlink($temp_file);

        if (is_wp_error($unzip_result) || $unzip_result !== true) {
            error_log('Custom Events Updater: Entpacken fehlgeschlagen');
            $this->remove_directory($temp_dir);
            return false;
        }

        // GitHub erstellt Ordner mit "repo-main" oder "org-repo-sha" Namen
        $extracted_dir = $temp_dir . '/' . $this->plugin_slug . '-main';

        if (!is_dir($extracted_dir)) {
            // Fallback: Ersten Unterordner suchen (API Zipball nutzt anderes Namensformat)
            $dirs = glob($temp_dir . '/*', GLOB_ONLYDIR);
            if (!empty($dirs)) {
                $extracted_dir = $dirs[0];
            } else {
                error_log('Custom Events Updater: Extrahierter Ordner nicht gefunden: ' . $extracted_dir);
                $this->remove_directory($temp_dir);
                return false;
            }
        }

        $this->clean_plugin_directory($plugin_dir);
        $this->copy_directory($extracted_dir, $plugin_dir);
        $this->remove_directory($temp_dir);

        $new_version = $this->get_remote_version();
        if ($new_version) {
            $this->save_local_version($new_version);
        }

        update_option($this->plugin_slug . '_last_update', [
            'time' => current_time('mysql'),
            'version' => $new_version
        ]);

        error_log('Custom Events Updater: Plugin erfolgreich auf ' . $new_version . ' aktualisiert');

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        return true;
    }

    private function clean_plugin_directory($dir) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if (strpos($file->getPathname(), '.git-version') !== false) {
                continue;
            }

            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
    }

    private function copy_directory($src, $dst) {
        $dir = opendir($src);
        @mkdir($dst, 0755, true);

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;

            $src_path = $src . '/' . $file;
            $dst_path = $dst . '/' . $file;

            if (is_dir($src_path)) {
                $this->copy_directory($src_path, $dst_path);
            } else {
                copy($src_path, $dst_path);
            }
        }

        closedir($dir);
    }

    private function remove_directory($dir) {
        if (!is_dir($dir)) return;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($dir);
    }

    public function show_update_notice() {
        $update_success = get_transient('cev_update_success');
        if ($update_success) {
            delete_transient('cev_update_success');
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>Custom Events Plugin aktualisiert!</strong> ';
            echo 'Version: <code>' . esc_html($update_success) . '</code>';
            echo '</p></div>';
        }
    }

    public function get_last_error() {
        return $this->last_error ?? null;
    }
}

new Custom_Events_GitHub_Updater();
