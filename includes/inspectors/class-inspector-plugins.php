<?php
/**
 * WP Security Guard - Plugins Inspector
 *
 * インストール済みプラグインの一覧、有効/無効状態、バージョン、
 * 利用可能な更新、旧版プラグインの残存を取得する。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Inspector_Plugins extends WPSG_Inspector_Base {

    /**
     * 検出対象の旧版プラグイン
     * 残存していたら警告を出す
     *
     * @var array
     */
    const LEGACY_PLUGINS = array(
        'wp-full-pay-fm-premium/wp-full-pay-fm-premium.php' => array(
            'name'        => 'WP Full Pay (Premium)',
            'replacement' => 'wp-full-stripe-free',
            'reason'      => 'Mammothology製の旧版。Themeisle版(wp-full-stripe-free)に置き換わっています',
        ),
        'wp-full-pay-members/wp-full-pay-members.php' => array(
            'name'        => 'WP Full Pay Members',
            'replacement' => 'wp-full-stripe-members',
            'reason'      => 'Mammothology製の旧版。Themeisle版(wp-full-stripe-members)に置き換わっています',
        ),
    );

    /**
     * @inheritDoc
     */
    public function inspect() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugin_files = (array) get_option('active_plugins', array());
        $must_use_plugins = function_exists('get_mu_plugins') ? get_mu_plugins() : array();
        $dropins = function_exists('get_dropins') ? get_dropins() : array();
        $update_data = $this->get_plugin_updates();

        // 自動更新が有効化されているプラグイン一覧 (WordPress 5.5+)
        // wp_options.auto_update_plugins に plugin_file (例: "akismet/akismet.php") の配列で保存される
        $auto_update_plugins = (array) get_option('auto_update_plugins', array());

        // v2.4.9: 自動更新の UI 表示可否を判定するための情報を取得
        // WordPress プラグイン一覧画面で「自動更新を有効化/無効化」リンクが表示されるかどうかは、
        // 以下の条件すべてを満たす場合のみ true:
        //   1. サイト全体で自動更新が有効 (AUTOMATIC_UPDATER_DISABLED が false)
        //   2. プラグインが update_plugins トランジェントの response/no_update に含まれる
        //      (= wp.org に登録されている、または update_uri ヘッダーで更新元が判明している)
        //   3. wp_is_auto_update_forced_for_item() が null を返す (フィルターで強制されていない)
        $auto_update_globally_enabled = $this->is_auto_update_globally_enabled();
        $update_plugins_transient = get_site_transient('update_plugins');
        $update_response_keys = array();
        $update_no_update_keys = array();
        if (is_object($update_plugins_transient)) {
            if (isset($update_plugins_transient->response) && is_array($update_plugins_transient->response)) {
                $update_response_keys = array_keys($update_plugins_transient->response);
            }
            if (isset($update_plugins_transient->no_update) && is_array($update_plugins_transient->no_update)) {
                $update_no_update_keys = array_keys($update_plugins_transient->no_update);
            }
        }
        $known_to_wp_update = array_unique(array_merge($update_response_keys, $update_no_update_keys));

        $context = array(
            'auto_update_plugins'           => $auto_update_plugins,
            'auto_update_globally_enabled'  => $auto_update_globally_enabled,
            'known_to_wp_update'            => $known_to_wp_update,
            'update_response_keys'          => $update_response_keys,
            'update_no_update_keys'         => $update_no_update_keys,
        );

        $active = array();
        $inactive = array();
        $legacy_residual_count = 0;
        $legacy_residual_files = array();

        foreach ($all_plugins as $plugin_file => $plugin_info) {
            $is_active = in_array($plugin_file, $active_plugin_files, true)
                || (is_multisite() && is_plugin_active_for_network($plugin_file));

            $entry = $this->plugin_to_array($plugin_file, $plugin_info, $update_data, $context);

            // 旧版プラグインの判定
            $legacy_info = $this->check_legacy($plugin_file);
            if ($legacy_info !== null) {
                $entry['is_legacy'] = true;
                $entry['legacy_replacement'] = $legacy_info['replacement'];
                $entry['legacy_reason'] = $legacy_info['reason'];
                $legacy_residual_count++;
                $legacy_residual_files[] = $plugin_file;
            }

            if ($is_active) {
                $active[] = $entry;
            } else {
                $inactive[] = $entry;
            }
        }

        // must-use プラグインを整形
        $must_use_formatted = array();
        foreach ($must_use_plugins as $plugin_file => $plugin_info) {
            $must_use_formatted[] = $this->plugin_to_array($plugin_file, $plugin_info, array(), $context);
        }

        // dropin プラグインを整形
        $dropins_formatted = array();
        foreach ($dropins as $plugin_file => $plugin_info) {
            $dropins_formatted[] = $this->plugin_to_array($plugin_file, $plugin_info, array(), $context);
        }

        // 自動更新統計 (v2.4.9 拡張: 「自動更新非サポート」のリストも分離)
        $auto_update_active_count = 0;
        $auto_update_disabled_active = array();        // アクティブ + 自動更新が無効 + UI で切替可能
        $auto_update_unsupported_active = array();     // アクティブ + UI で切替不可 (自動更新非サポート)
        foreach ($active as $a) {
            if (!empty($a['auto_update'])) {
                $auto_update_active_count++;
                continue;
            }
            $entry_brief = array(
                'file'    => $a['file'],
                'slug'    => $a['slug'],
                'name'    => $a['name'],
                'version' => $a['version'],
                'author'  => $a['author'],
                'auto_update_supported' => !empty($a['auto_update_supported']),
                'auto_update_unsupported_reason' => isset($a['auto_update_unsupported_reason'])
                    ? $a['auto_update_unsupported_reason'] : null,
            );
            if (!empty($a['auto_update_supported'])) {
                $auto_update_disabled_active[] = $entry_brief;
            } else {
                $auto_update_unsupported_active[] = $entry_brief;
            }
        }

        $data = array(
            'active'                => $active,
            'inactive'              => $inactive,
            'must_use'              => $must_use_formatted,
            'drop_ins'              => $dropins_formatted,
            'total_active'          => count($active),
            'total_inactive'        => count($inactive),
            'total_installed'       => count($all_plugins),
            'legacy_residual_count' => $legacy_residual_count,
            'updates_available'     => $this->count_updates_available($all_plugins, $update_data),
            // v2.4.8: 自動更新統計
            'auto_update_active_count'        => $auto_update_active_count,
            'auto_update_disabled_active'     => $auto_update_disabled_active,
            'auto_update_unsupported_active'  => $auto_update_unsupported_active,
            'auto_update_total'               => count($auto_update_plugins),
            'auto_update_globally_enabled'    => $auto_update_globally_enabled,
        );

        // 旧版残存があれば警告を追加
        if ($legacy_residual_count > 0) {
            $this->add_warning(
                'warning',
                'legacy_plugin_residual',
                sprintf('旧版プラグインが%d件残存しています', $legacy_residual_count),
                array('files' => $legacy_residual_files)
            );
        }

        return $data;
    }

    /**
     * プラグイン情報を配列に整形する
     *
     * @param string $plugin_file
     * @param array  $plugin_info
     * @param array  $update_data
     * @return array
     */
    private function plugin_to_array($plugin_file, $plugin_info, $update_data, $context = array()) {
        // $context の旧形式 (array of slugs) との後方互換: 配列で「数値キー」のみなら旧形式
        if (is_array($context) && !empty($context) && array_keys($context) === range(0, count($context) - 1)) {
            // 旧形式: $auto_update_plugins (slug の配列) が直接渡された
            $auto_update_plugins = $context;
            $context = array(
                'auto_update_plugins'           => $auto_update_plugins,
                'auto_update_globally_enabled'  => true,  // 旧呼び出しでは判定不能
                'known_to_wp_update'            => array(),
                'update_response_keys'          => array(),
                'update_no_update_keys'         => array(),
            );
        } elseif (!is_array($context)) {
            $context = array();
        }

        $auto_update_plugins = isset($context['auto_update_plugins'])
            ? (array) $context['auto_update_plugins'] : array();
        $auto_update_globally_enabled = !empty($context['auto_update_globally_enabled']);
        $known_to_wp_update = isset($context['known_to_wp_update'])
            ? (array) $context['known_to_wp_update'] : array();

        $slug = $this->extract_slug($plugin_file);
        $is_known_to_wp = in_array($plugin_file, $known_to_wp_update, true);

        // v2.4.9: 自動更新 UI の表示可否を判定
        // WordPress プラグイン一覧画面で「自動更新を有効化/無効化」リンクが表示されるかと同等の判定。
        $auto_update_supported = false;
        $auto_update_unsupported_reason = null;

        if (!$auto_update_globally_enabled) {
            $auto_update_unsupported_reason = 'site_globally_disabled';
        } elseif (!$is_known_to_wp) {
            // wp.org の更新チェック対象外 = 自動更新の仕組みが使えないプラグイン
            // (自作プラグイン、CodeCanyon などの有償プラグインで update_uri 未対応のもの)
            $auto_update_unsupported_reason = 'not_known_to_wp_update_api';
        } else {
            // wp_is_auto_update_forced_for_item() で強制状態を確認
            // (auto_update_plugin フィルターが boolean を返す場合 = 強制状態)
            $forced = $this->check_auto_update_forced($plugin_file, $plugin_info);
            if ($forced === true) {
                $auto_update_supported = false;
                $auto_update_unsupported_reason = 'forced_enabled_by_filter';
            } elseif ($forced === false) {
                $auto_update_supported = false;
                $auto_update_unsupported_reason = 'forced_disabled_by_filter';
            } else {
                // 強制されていない = UI で切替可能
                $auto_update_supported = true;
            }
        }

        $entry = array(
            'slug'       => $slug,
            'file'       => $plugin_file,
            'name'       => isset($plugin_info['Name']) ? wp_strip_all_tags((string) $plugin_info['Name']) : '',
            'version'    => isset($plugin_info['Version']) ? (string) $plugin_info['Version'] : '',
            'author'     => isset($plugin_info['Author']) ? wp_strip_all_tags((string) $plugin_info['Author']) : '',
            'plugin_uri' => isset($plugin_info['PluginURI']) ? (string) $plugin_info['PluginURI'] : '',
            'description'=> isset($plugin_info['Description']) ? wp_strip_all_tags((string) $plugin_info['Description']) : '',
            'requires_wp'  => isset($plugin_info['RequiresWP']) ? (string) $plugin_info['RequiresWP'] : '',
            'requires_php' => isset($plugin_info['RequiresPHP']) ? (string) $plugin_info['RequiresPHP'] : '',
            'update_uri'   => isset($plugin_info['UpdateURI']) ? (string) $plugin_info['UpdateURI'] : '',
            'is_legacy'  => false,
            // v2.4.8: 自動更新フラグ (WordPress 5.5+)
            'auto_update'  => in_array($plugin_file, $auto_update_plugins, true),
            // v2.4.9: 自動更新 UI が使えるプラグインか (WordPress 一覧画面でリンクが出るか)
            'auto_update_supported' => $auto_update_supported,
            'auto_update_unsupported_reason' => $auto_update_unsupported_reason,
            'known_to_wp_update_api' => $is_known_to_wp,
        );

        // 更新情報
        if (isset($update_data[$plugin_file])) {
            $update = $update_data[$plugin_file];
            $entry['update_available'] = true;
            $entry['new_version'] = isset($update->new_version) ? (string) $update->new_version : null;
        } else {
            $entry['update_available'] = false;
        }

        return $entry;
    }

    /**
     * プラグインファイルパスからスラッグを抽出する
     *
     * 例: "ultimate-member/index.php" → "ultimate-member"
     *     "hello.php"                 → "hello"
     *
     * @param string $plugin_file
     * @return string
     */
    private function extract_slug($plugin_file) {
        if (strpos($plugin_file, '/') !== false) {
            $parts = explode('/', $plugin_file);
            return $parts[0];
        }
        // 単一ファイルの場合はファイル名から拡張子を除去
        return basename($plugin_file, '.php');
    }

    /**
     * 旧版プラグインかどうか判定する
     *
     * @param string $plugin_file
     * @return array|null 旧版なら情報配列、そうでなければnull
     */
    private function check_legacy($plugin_file) {
        if (isset(self::LEGACY_PLUGINS[$plugin_file])) {
            return self::LEGACY_PLUGINS[$plugin_file];
        }
        return null;
    }

    /**
     * プラグイン更新情報を取得
     *
     * @return array key=plugin_file, value=update object
     */
    private function get_plugin_updates() {
        $updates = get_site_transient('update_plugins');
        if (is_object($updates) && !empty($updates->response) && is_array($updates->response)) {
            return $updates->response;
        }
        return array();
    }

    /**
     * 更新が利用可能なプラグイン数を数える
     *
     * @param array $all_plugins
     * @param array $update_data
     * @return int
     */
    private function count_updates_available($all_plugins, $update_data) {
        $count = 0;
        foreach (array_keys($all_plugins) as $plugin_file) {
            if (isset($update_data[$plugin_file])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * サイト全体で自動更新が有効化されているか
     *
     * WordPress の wp_is_auto_update_enabled_for_type('plugin') 相当の判定。
     * AUTOMATIC_UPDATER_DISABLED 定数や automatic_updater_disabled / plugins_auto_update_enabled
     * フィルターで無効化されていない場合のみ true を返す。
     *
     * @return bool
     */
    private function is_auto_update_globally_enabled() {
        if (!function_exists('wp_is_auto_update_enabled_for_type')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        if (function_exists('wp_is_auto_update_enabled_for_type')) {
            return (bool) wp_is_auto_update_enabled_for_type('plugin');
        }
        // フォールバック: 古い WP 用
        if (defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED) {
            return false;
        }
        return true;
    }

    /**
     * プラグインの自動更新が auto_update_plugin フィルター等で強制されているか確認
     *
     * 戻り値:
     *   true  - フィルターで強制的に有効化されている (UI 切替不可)
     *   false - フィルターで強制的に無効化されている (UI 切替不可)
     *   null  - 強制されていない (UI で切替可能)
     *
     * @param string $plugin_file
     * @param array  $plugin_info
     * @return bool|null
     */
    private function check_auto_update_forced($plugin_file, $plugin_info) {
        // wp_is_auto_update_forced_for_item を呼び出すには WP_Site_Health の参照や $item オブジェクトが
        // 必要だが、内部で呼ばれている apply_filters( "auto_update_{$type}", $update, $item ) は
        // null を渡しても評価可能なので、それを再現する。
        $item = (object) array(
            'plugin' => $plugin_file,
            'slug'   => $this->extract_slug($plugin_file),
            'new_version' => isset($plugin_info['Version']) ? (string) $plugin_info['Version'] : '',
        );
        $result = apply_filters('auto_update_plugin', null, $item);
        if ($result === null) {
            return null;
        }
        return (bool) $result;
    }
}
