<?php
/**
 * WP Security Guard - Core Inspector
 *
 * WordPressコア・PHP・MySQLなど環境情報を取得する。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Inspector_Core extends WPSG_Inspector_Base {

    /**
     * @inheritDoc
     */
    public function inspect() {
        $wpdb = $this->wpdb();

        $data = array(
            'wp_version'      => get_bloginfo('version'),
            'site_url'        => home_url(),
            'admin_url'       => admin_url(),
            'is_multisite'    => is_multisite(),
            'php_version'     => PHP_VERSION,
            'php_major_minor' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'mysql_version'   => $this->get_mysql_version(),
            'wp_debug'        => defined('WP_DEBUG') && WP_DEBUG,
            'wp_debug_log'    => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
            'wp_debug_display'=> defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY,
            'wp_environment'  => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown',
            'language'        => get_locale(),
            'timezone'        => wp_timezone_string(),
            'memory_limit'    => ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'wp_max_upload_size'  => $this->format_bytes(wp_max_upload_size()),
            'db_charset'      => $wpdb->charset,
            'db_collate'      => $wpdb->collate,
            'table_prefix'    => $wpdb->prefix,
            'wp_content_url'  => content_url(),
            'permalink_structure' => get_option('permalink_structure', ''),
        );

        // WP_DEBUG が本番環境で有効な場合は警告
        if ($data['wp_debug'] && $data['wp_environment'] === 'production') {
            $this->add_warning(
                'warning',
                'wp_debug_in_production',
                'WP_DEBUGが本番環境で有効になっています'
            );
        }

        // 利用可能なWP本体の更新を確認
        $core_update = $this->get_core_update_info();
        $data['core_update_available'] = $core_update['available'];
        if (!empty($core_update['new_version'])) {
            $data['core_new_version'] = $core_update['new_version'];
        }

        return $data;
    }

    /**
     * MySQL/MariaDBのバージョンを取得
     *
     * @return string
     */
    private function get_mysql_version() {
        $wpdb = $this->wpdb();
        $version = $wpdb->get_var('SELECT VERSION()');
        return is_string($version) ? $version : '';
    }

    /**
     * WP本体の更新情報
     *
     * @return array ['available' => bool, 'new_version' => string|null]
     */
    private function get_core_update_info() {
        if (!function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        $updates = get_core_updates(array('dismissed' => false));
        if (is_array($updates) && !empty($updates)) {
            $first = $updates[0];
            if (isset($first->response) && $first->response === 'upgrade') {
                return array(
                    'available'   => true,
                    'new_version' => isset($first->version) ? $first->version : null,
                );
            }
        }
        return array('available' => false, 'new_version' => null);
    }

    /**
     * バイト数を人間向けフォーマットに整形
     *
     * @param int $bytes
     * @return string
     */
    private function format_bytes($bytes) {
        if (!is_numeric($bytes)) {
            return (string) $bytes;
        }
        $bytes = (int) $bytes;
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes = $bytes / 1024;
            $i++;
        }
        return round($bytes, 2) . $units[$i];
    }
}
