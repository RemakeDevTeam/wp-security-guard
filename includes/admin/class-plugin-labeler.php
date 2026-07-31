<?php
/**
 * WP Security Guard - Plugin Labeler
 *
 * 管理画面「プラグイン」一覧で、各プラグイン名の後ろにシステム略語（【MS・OS】等）を
 * サブタイトル的に表示する。判定は次の優先順:
 *   1. プラグインヘッダー  System: マッチング  （＝任意で各プラグインに1行足す運用）
 *   2. 同梱の slug→システム略語マップ（フォールバック）
 *
 * 名称変更は不要。remakemanager / GitHub と同じ略語で「どのシステム用か」を可視化する。
 *
 * @package WPSecurityGuard
 * @since 2.5.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Plugin_Labeler {

    /**
     * 同梱フォールバックマップ（正規化slug => システム略語表記）。
     * remakemanager の分類と一致。third-party / インフラは含めない。
     *
     * @return array
     */
    public static function default_map() {
        return array(
            'wp-security-guard'                    => 'MS・OS・LC・ECM・CRMS・LCF',
            'spam-guard-cf7'                       => 'MS・OS・LC・ECM・CRMS・LCF',
            'um-customizer'                        => 'MS・OS',
            'campaignsp'                           => 'MS・OS',
            'my-ajax-chat'                         => 'MS・OS',
            'plugin_custom_support'                => 'MS',
            'bankpay'                              => 'MS・OS',
            'bankpay-flexible-billing-cycle'       => 'MS・OS',
            'step-post'                            => 'OS・CRMS',
            'live-web'                             => 'ECM・LC・MS',
            'plugin_custom_support_os'             => 'OS',
            'custom_invitation_code'               => 'MS・OS',
            'customize_sensei_lms'                 => 'OS',
            'custom-news-blog-settings'            => 'OS',
            'um-customizer-for-crm'                => 'CRMS',
            'um-customizer_for_crmbankpay'         => 'CRMS',
            'customizer_for_crmbankpay'            => 'CRMS',
            'crm-kantan'                           => 'CRMS',
            'stripe-management-for-crm-settlement' => 'CRMS',
            'plugin-customizer'                    => 'ECM',
            'admin-vendors-manage'                 => 'ECM',
            'lc-customizer'                        => 'LC',
        );
    }

    /**
     * フック登録。
     */
    public static function init() {
        add_filter('extra_plugin_headers', array(__CLASS__, 'register_header'));
        add_filter('all_plugins', array(__CLASS__, 'label_plugins'));
    }

    /**
     * カスタムヘッダー System: を get_plugins() で読めるようにする。
     *
     * @param array $headers
     * @return array
     */
    public static function register_header($headers) {
        if (is_array($headers)) {
            $headers['System'] = 'System';
        }
        return $headers;
    }

    /**
     * プラグイン一覧の各 Name にシステム略語を付す。
     *
     * @param array $plugins [plugin_file => data]
     * @return array
     */
    public static function label_plugins($plugins) {
        if (!is_array($plugins)) {
            return $plugins;
        }
        foreach ($plugins as $file => $data) {
            $label = self::label_for($file, $data);
            if ($label === '') {
                continue;
            }
            $name = isset($data['Name']) ? $data['Name'] : $file;
            $plugins[$file]['Name'] = $name
                . ' <span class="wpsg-syslabel" style="color:#3730a3;font-weight:600;font-size:12px;">【' . $label . '】</span>';
        }
        return $plugins;
    }

    /**
     * 1プラグインのシステム略語を返す（ヘッダー優先→マップ）。
     *
     * @param string $file プラグインファイル（folder/main.php）。
     * @param array  $data プラグインヘッダーデータ。
     * @return string 略語（無ければ空）。
     */
    private static function label_for($file, $data) {
        // 1. System ヘッダー（各プラグインが宣言していれば最優先）。
        if (is_array($data) && !empty($data['System'])) {
            return esc_html(trim((string) $data['System']));
        }
        // 2. 同梱マップ（正規化slug）。
        $slug = strtok((string) $file, '/');
        $key  = self::normalize_slug($slug);
        $map  = apply_filters('wpsg_plugin_system_map', self::default_map());
        if (is_array($map) && isset($map[$key])) {
            return esc_html($map[$key]);
        }
        return '';
    }

    /**
     * slug 正規化（フォルダ名の版/old/main 揺れを吸収。remakemanager と同一規則）。
     *
     * @param string $slug
     * @return string
     */
    private static function normalize_slug($slug) {
        $s = strtolower(trim((string) $slug));
        $s = preg_replace('/[-_](old|main)$/', '', $s);
        $s = preg_replace('/[-_]v?[0-9]+(\.[0-9]+)*$/', '', $s);
        $s = preg_replace('/[-_](old|main)$/', '', $s);
        return ($s !== '') ? $s : strtolower(trim((string) $slug));
    }
}
