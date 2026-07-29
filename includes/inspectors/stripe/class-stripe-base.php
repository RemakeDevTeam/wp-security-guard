<?php
/**
 * WP Security Guard - Stripe Inspector Base
 *
 * Stripe決済関連の各実装inspectorの抽象基底クラス。
 * 機微情報のマスク処理を共通化する。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class WPSG_Stripe_Inspector_Base {

    /** @var array */
    protected $warnings = array();

    /**
     * 当該実装がアクティブか
     *
     * @return bool
     */
    abstract public function is_active();

    /**
     * 実装識別子
     *
     * @return string
     */
    abstract public function get_implementation_id();

    /**
     * 設定情報を取得 (機微情報はマスク済み)
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
    // 機微情報マスクヘルパー
    // ========================================================================

    /**
     * 鍵の先頭8文字のみ返す (例: "pk_live_xxxxxxxx" → "pk_live_")
     * 機微情報は絶対にそのまま返さない方針
     *
     * @param string|null $key
     * @param int $length 先頭何文字を返すか
     * @return string|null
     */
    protected function key_prefix($key, $length = 8) {
        if (!is_string($key) || $key === '') {
            return null;
        }
        return substr($key, 0, $length);
    }

    /**
     * 値が設定されているか (空文字列を未設定として扱う)
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
     * 共通レスポンス構造のテンプレート
     * サブクラスはこれをマージして使う
     *
     * @param array $overrides
     * @return array
     */
    protected function build_response(array $overrides = array()) {
        $defaults = array(
            'implementation'           => $this->get_implementation_id(),
            'version'                  => null,
            'enabled'                  => null,
            'mode'                     => 'unknown',  // 'live'|'test'|'configured'|'unconfigured'|'unknown'
            'publishable_key_set'      => false,
            'publishable_key_prefix'   => null,
            'secret_key_set'           => false,
            'test_publishable_key_set' => false,
            'test_publishable_key_prefix' => null,
            'test_secret_key_set'      => false,
            'webhook_configured'       => false,
            'live_webhook_set'         => false,
            'test_webhook_set'         => false,
            'test_mode_switchable'     => true,
            'currency'                 => null,
            'settings_url'             => null,
            'custom'                   => array(),
        );
        return array_merge($defaults, $overrides);
    }

    /**
     * オプション値の中から「最初に見つかった非空の値」を返す
     * 命名のゆらぎ対応 (pk_live / publishable_key / livePublishableKey 等)
     *
     * @param array $haystack 連想配列
     * @param array $keys 候補キー(優先度順)
     * @return mixed|null
     */
    protected function find_value(array $haystack, array $keys) {
        foreach ($keys as $key) {
            if (isset($haystack[$key]) && $haystack[$key] !== '' && $haystack[$key] !== null) {
                return $haystack[$key];
            }
        }
        return null;
    }

    /**
     * 候補キーから、haystack に存在し値が空でない最初のキー名を返す
     *
     * find_value() と対になる関数。値ではなくキー名を返すため、
     * 例えば「test_mode が見つかったか live_mode が見つかったか」を判定するのに使う。
     *
     * @param array $haystack 連想配列
     * @param array $keys 候補キー(優先度順)
     * @return string|null 見つかったキー名 (なければ null)
     */
    protected function find_matching_key(array $haystack, array $keys) {
        foreach ($keys as $key) {
            if (isset($haystack[$key]) && $haystack[$key] !== '' && $haystack[$key] !== null) {
                return $key;
            }
        }
        return null;
    }
}
