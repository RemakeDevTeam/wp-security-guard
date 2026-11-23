<?php
/**
 * WP Security Guard - Mail Inspector Base
 *
 * メール送信プラグイン（Easy WP SMTP / MailPoet 等）別 inspector の抽象基底クラス。
 * Stripe inspector と対称。機微情報（SMTPパスワード・APIキー）は真偽のみに落とし、
 * 値・接頭辞・復号結果は一切返さない方針を共通化する。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.10.1
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class WPSG_Mail_Inspector_Base {

    /** @var array */
    protected $warnings = array();

    /**
     * 当該実装がアクティブか
     *
     * @return bool
     */
    abstract public function is_active();

    /**
     * 実装識別子（例: 'easy-wp-smtp' / 'mailpoet'）
     *
     * @return string
     */
    abstract public function get_implementation_id();

    /**
     * 設定情報を取得（機微はマスク済み）
     *
     * @return array
     */
    abstract public function get_data();

    /**
     * 警告取得
     *
     * @return array
     */
    public function get_warnings() {
        return $this->warnings;
    }

    protected function add_warning($level, $code, $message, $details = array()) {
        $this->warnings[] = array(
            'level'   => $level,
            'code'    => $code,
            'message' => $message,
            'details' => $details,
        );
    }

    // ========================================================================
    // 機微情報マスク／検出ヘルパー（Stripe基底と同一の作法）
    // ========================================================================

    /**
     * 値が設定されているか（空文字列を未設定として扱う）。
     * パスワード・APIキーは必ずこれ（真偽）だけで表現する。
     *
     * @param mixed $val
     * @return bool
     */
    protected function is_set($val) {
        if ($val === null || $val === false) return false;
        if (is_string($val)) return $val !== '';
        return !empty($val);
    }

    /**
     * プラグインが有効か
     */
    protected function is_plugin_active($plugin_file) {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active($plugin_file);
    }

    /**
     * インストール済みプラグインのバージョンを取得
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
     * 連想配列から「最初に見つかった非空の値」を返す（命名ゆらぎ吸収）。
     *
     * @param array $haystack
     * @param array $keys 候補キー（優先度順）
     * @return mixed|null
     */
    protected function find_value($haystack, array $keys) {
        if (!is_array($haystack)) {
            return null;
        }
        foreach ($keys as $key) {
            if (isset($haystack[$key]) && $haystack[$key] !== '' && $haystack[$key] !== null) {
                return $haystack[$key];
            }
        }
        return null;
    }

    /**
     * グローバル $wpdb への安全なアクセス（MailPoet の独自テーブル用）
     *
     * @return wpdb
     */
    protected function wpdb() {
        global $wpdb;
        return $wpdb;
    }

    /**
     * テーブルの存在確認
     *
     * @param string $table_name 完全なテーブル名（プレフィックス込み）
     * @return bool
     */
    protected function table_exists($table_name) {
        $wpdb = $this->wpdb();
        if (!$wpdb) {
            return false;
        }
        $result = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        return $result === $table_name;
    }

    /**
     * 共通レスポンス構造のテンプレート。サブクラスはこれをマージして使う。
     * host/port/encryption/auth/username/from_* は平文可。password は真偽のみ。
     *
     * @param array $overrides
     * @return array
     */
    protected function build_response(array $overrides = array()) {
        $defaults = array(
            'implementation' => $this->get_implementation_id(),
            'version'        => null,
            'active'         => true,
            'mailer'         => 'unknown', // 'smtp' | 'SMTP' | 'PHPMail' | ... 送信方式
            'host'           => null,      // 平文
            'port'           => null,      // 平文
            'encryption'     => null,      // 'none'|'ssl'|'tls' 平文
            'auth'           => null,      // bool: 認証を使うか
            'username'       => null,      // 平文（ログインID）
            'password_set'   => false,     // ★真偽のみ。値・接頭辞・復号は一切出さない
            'from_email'     => null,      // 平文
            'from_name'      => null,      // 平文
            'settings_url'   => null,
            'custom'         => array(),
        );
        return array_merge($defaults, $overrides);
    }
}
