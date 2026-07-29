<?php
/**
 * WP Security Guard - Membership Inspector Base Class
 *
 * 会員管理プラグイン別のinspectorが継承する抽象基底クラス。
 * 全実装を「products」概念で統一表現する。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class WPSG_Membership_Inspector_Base {

    /**
     * 警告メッセージ配列
     * @var array
     */
    protected $warnings = array();

    /**
     * 当該プラグイン/機能がこのサイトでアクティブか
     *
     * @return bool
     */
    abstract public function is_active();

    /**
     * 実装識別子(レスポンスのkeyに使用)
     * 例: 'ultimate-member', 'wp-full-stripe', 'bankpay'
     *
     * @return string
     */
    abstract public function get_implementation_id();

    /**
     * 製品(products)情報を返す
     * 会員プラン/サブスク/商品/プロジェクト等を統一表現
     *
     * @return array 各製品の連想配列の配列
     */
    abstract public function get_products();

    /**
     * 当該実装に関するメタデータを返す
     * バージョン情報・統計情報・健全性チェック結果など
     *
     * @return array
     */
    abstract public function get_metadata();

    /**
     * 蓄積された警告を取得する
     *
     * @return array
     */
    public function get_warnings() {
        return $this->warnings;
    }

    /**
     * 警告を追加する
     *
     * @param string $level   warning|error|notice
     * @param string $code    短い識別子
     * @param string $message 人間向けメッセージ
     * @param array  $details 補足情報
     */
    protected function add_warning($level, $code, $message, $details = array()) {
        $this->warnings[] = array(
            'level'   => $level,
            'code'    => $code,
            'message' => $message,
            'details' => $details,
        );
    }

    // ========================================================================
    // 共通ヘルパー
    // ========================================================================

    /**
     * グローバル$wpdbへの安全なアクセス
     *
     * @return wpdb
     */
    protected function wpdb() {
        global $wpdb;
        return $wpdb;
    }

    /**
     * テーブル存在確認
     *
     * @param string $table_name 完全修飾テーブル名
     * @return bool
     */
    protected function table_exists($table_name) {
        $wpdb = $this->wpdb();
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
    }

    /**
     * テーブルのカラム名一覧を取得
     *
     * @param string $table_name 完全修飾テーブル名
     * @return array カラム名の配列
     */
    protected function get_table_columns($table_name) {
        if (!$this->table_exists($table_name)) {
            return array();
        }
        $wpdb = $this->wpdb();
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`");
        return is_array($columns) ? $columns : array();
    }

    /**
     * プラグインが有効か(プラグインファイル名で判定)
     *
     * @param string $plugin_file
     * @return bool
     */
    protected function is_plugin_active($plugin_file) {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active($plugin_file);
    }

    /**
     * インストール済みプラグインのバージョンを取得する
     *
     * @param string $plugin_file
     * @return string|null バージョン文字列。プラグインが存在しない場合はnull
     */
    protected function get_plugin_version($plugin_file) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_plugins();
        if (isset($all[$plugin_file]['Version'])) {
            return (string) $all[$plugin_file]['Version'];
        }
        return null;
    }

    /**
     * オプション値を bool として解釈
     * BankPay 等は string 'yes'/'1'/true 混在のため
     *
     * @param string $key wp_optionsキー
     * @param bool   $default
     * @return bool
     */
    protected function get_bool_option($key, $default = false) {
        $val = get_option($key, $default);
        if (is_bool($val)) return $val;
        if (is_int($val)) return $val !== 0;
        if (is_string($val)) {
            $lower = strtolower(trim($val));
            return in_array($lower, array('1', 'true', 'yes', 'on'), true);
        }
        return (bool) $default;
    }
}
