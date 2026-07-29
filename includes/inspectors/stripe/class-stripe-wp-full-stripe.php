<?php
/**
 * WP Security Guard - WP Full Stripe (Themeisle) Stripe Inspector
 *
 * Themeisle版WP Full StripeのStripe鍵設定を取得する。
 * オプションキーは未確定のため、複数候補を順に試す。
 * 確認できたオプション名は custom.option_name_used として返す。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Stripe_WP_Full_Stripe extends WPSG_Stripe_Inspector_Base {

    /**
     * Stripe鍵を保持する可能性があるオプション名(優先度順)
     *
     * 確認済み (Mammothology / Themeisle WP Full Stripe ソースより):
     *   - fullstripe_options_f       (Free 版、現行)
     *   - fullstripe_options         (Pro 版、旧 Mammothology v2.x ~)
     *
     * その他、互換性確保のためのフォールバック候補も含む
     */
    const OPTION_CANDIDATES = array(
        'fullstripe_options_f',          // Free 版 (確認済)
        'fullstripe_options',            // Pro 版 / 旧 Mammothology (確認済)
        'fullstripe-options',            // ハイフン版
        'fullstripe-options-f',          // Free 版ハイフン版
        // 新版 (v7.0+ Stripe Connect) ありうるパターン
        'wpfs_options',
        'wpfs-options',
        'wpfs_settings',
        'wp-full-pay-options',
        'wp_full_pay_options',
        'wp_full_pay_settings',
        'wp-full-stripe-options',
        'wp_full_stripe_options',
        'wp_full_stripe_settings',
        'wpFullPayOptions',
        'wpFullStripeOptions',
    );

    /** Stripe鍵カラム名のゆらぎ対応(live系)
     *  - 確認済 (旧版 Mammothology): publishKey_live (PK), secretKey_live (SK)
     *  - 互換のために他の表記も併記
     */
    const LIVE_PK_KEYS = array(
        'publishKey_live',                    // 旧版 Mammothology (確認済)
        'live_publishable_key', 'livePublishableKey', 'live_pk', 'pk_live', 'publishable_key_live',
    );
    const LIVE_SK_KEYS = array(
        'secretKey_live',                     // 旧版 Mammothology (確認済)
        'live_secret_key', 'liveSecretKey', 'live_sk', 'sk_live', 'secret_key_live',
    );
    /** Stripe鍵カラム名のゆらぎ対応(test系) */
    const TEST_PK_KEYS = array(
        'publishKey_test',                    // 旧版 Mammothology (確認済)
        'test_publishable_key', 'testPublishableKey', 'test_pk', 'pk_test', 'publishable_key_test',
    );
    const TEST_SK_KEYS = array(
        'secretKey_test',                     // 旧版 Mammothology (確認済)
        'test_secret_key', 'testSecretKey', 'test_sk', 'sk_test', 'secret_key_test',
    );
    /** Stripe鍵: モード非依存型(古いプラグインだとmode別ではなく単一の場合あり) */
    const GENERIC_PK_KEYS = array('publishable_key', 'publishableKey', 'pk');
    const GENERIC_SK_KEYS = array('secret_key', 'secretKey', 'sk');
    /** モードキー
     *  - 確認済 (Mammothology): apiMode ('test' / 'live')
     */
    const MODE_KEYS = array('apiMode', 'mode', 'api_mode', 'live_mode', 'test_mode');
    /** 通貨キー */
    const CURRENCY_KEYS = array('currency', 'defaultCurrency', 'default_currency');
    /** Webhookキー */
    const WEBHOOK_KEYS = array('webhook_secret', 'webhookSecret', 'live_webhook_secret');
    const TEST_WEBHOOK_KEYS = array('test_webhook_secret', 'testWebhookSecret');

    public function is_active() {
        // WP Full Stripe の各種フォルダ名・ファイル名パターンに対応
        // - wp-full-stripe-free (Free 版)
        // - wp-full-stripe (有償版・標準フォルダ名)
        // - wp-full-stripe-pro (有償版・カスタムフォルダ名)
        // 上記いずれかで is_plugin_active が true なら検出
        $candidates = array(
            'wp-full-stripe-free/wp-full-stripe-free.php',
            'wp-full-stripe/wp-full-stripe.php',
            'wp-full-stripe-pro/wp-full-stripe.php',
            'wp-full-stripe-pro/wp-full-stripe-pro.php',
        );
        foreach ($candidates as $file) {
            if ($this->is_plugin_active($file)) {
                return true;
            }
        }
        // 関数 / クラス検出のフォールバック
        if (function_exists('mm_wpfs')) {
            return true;
        }
        if (class_exists('MM_WPFS')) {
            return true;
        }
        // 上記いずれもダメだったら、active_plugins から wp-full-stripe を含むファイルを総当たり
        $active_plugins = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $network_active = (array) get_site_option('active_sitewide_plugins', array());
            $active_plugins = array_merge($active_plugins, array_keys($network_active));
        }
        foreach ($active_plugins as $plugin_file) {
            $pf = strtolower((string) $plugin_file);
            // wp-full-stripe-members (アドオン) は除外
            if (strpos($pf, 'wp-full-stripe-members') !== false) {
                continue;
            }
            if (strpos($pf, 'wp-full-stripe') !== false) {
                return true;
            }
        }
        return false;
    }

    public function get_implementation_id() {
        return 'wp-full-stripe';
    }

    public function get_data() {
        $found = $this->find_settings();

        if ($found === null) {
            $this->add_warning(
                'warning',
                'wp_full_stripe_options_not_found',
                'WP Full Stripeの設定オプションが見つかりませんでした。WP-CLIで wp option list --search="*Stripe*" 等で実際のキー名をご確認ください',
                array('tried_options' => self::OPTION_CANDIDATES)
            );
            return $this->build_response(array(
                'version' => $this->get_plugin_version('wp-full-stripe-free/wp-full-stripe-free.php')
                    ?: $this->get_plugin_version('wp-full-stripe/wp-full-stripe.php'),
                'mode'    => 'unknown',
                'custom'  => array(
                    'option_name_used' => null,
                    'tried_options'    => self::OPTION_CANDIDATES,
                ),
            ));
        }

        $option_name = $found['_option_name'];
        $settings = $found;
        unset($settings['_option_name']);

        // モード推定 (堅牢化)
        $mode_raw = $this->find_value($settings, self::MODE_KEYS);
        $is_test = false;
        $is_live = false;
        $mode_decision = 'unknown';   // 判定経路の記録 (デバッグ用)

        if (is_string($mode_raw)) {
            $mode_lower = strtolower(trim($mode_raw));
            if ($mode_lower === 'test' || $mode_lower === 'sandbox') {
                $is_test = true;
                $mode_decision = 'string_test';
            } elseif ($mode_lower === 'live' || $mode_lower === 'production' || $mode_lower === 'prod') {
                $is_live = true;
                $mode_decision = 'string_live';
            } elseif (stripos($mode_lower, 'test') !== false) {
                $is_test = true;
                $mode_decision = 'string_contains_test';
            } elseif (stripos($mode_lower, 'live') !== false || stripos($mode_lower, 'prod') !== false) {
                $is_live = true;
                $mode_decision = 'string_contains_live';
            } else {
                $mode_decision = 'string_unknown_value';
            }
        } elseif (is_bool($mode_raw)) {
            // live_mode のような boolean フラグ
            // キー名が test_* の場合と live_* の場合で意味が逆転するため、見つかったキー名から判定
            $found_key = $this->find_matching_key($settings, self::MODE_KEYS);
            if ($found_key !== null) {
                if (stripos($found_key, 'test') !== false) {
                    $is_test = (bool) $mode_raw;
                    $is_live = !$mode_raw;
                    $mode_decision = 'bool_test_flag';
                } else {
                    // live_mode 等
                    $is_live = (bool) $mode_raw;
                    $is_test = !$mode_raw;
                    $mode_decision = 'bool_live_flag';
                }
            }
        } elseif (is_int($mode_raw) || is_numeric($mode_raw)) {
            // 数値型 (0/1) のフラグ
            $is_live = ((int) $mode_raw) === 1;
            $is_test = !$is_live;
            $mode_decision = 'numeric_flag';
        } elseif ($mode_raw === null || $mode_raw === false) {
            $mode_decision = 'mode_key_not_found';
        }

        // 鍵抽出
        $live_pk = $this->find_value($settings, self::LIVE_PK_KEYS);
        $live_sk = $this->find_value($settings, self::LIVE_SK_KEYS);
        $test_pk = $this->find_value($settings, self::TEST_PK_KEYS);
        $test_sk = $this->find_value($settings, self::TEST_SK_KEYS);

        // モード非依存の鍵 (古い実装) はモードに応じて振り分ける
        if (!$this->is_set($live_pk) && !$this->is_set($test_pk)) {
            $generic_pk = $this->find_value($settings, self::GENERIC_PK_KEYS);
            $generic_sk = $this->find_value($settings, self::GENERIC_SK_KEYS);
            if ($this->is_set($generic_pk)) {
                if ($is_test) { $test_pk = $generic_pk; }
                else { $live_pk = $generic_pk; }
            }
            if ($this->is_set($generic_sk)) {
                if ($is_test) { $test_sk = $generic_sk; }
                else { $live_sk = $generic_sk; }
            }
        }

        $live_wh = $this->find_value($settings, self::WEBHOOK_KEYS);
        $test_wh = $this->find_value($settings, self::TEST_WEBHOOK_KEYS);
        $currency = $this->find_value($settings, self::CURRENCY_KEYS);

        $webhook_configured = $is_test ? $this->is_set($test_wh) : $this->is_set($live_wh);

        // 鍵の整合性チェック
        if ($is_test) {
            if (!$this->is_set($test_pk) || !$this->is_set($test_sk)) {
                $this->add_warning(
                    'warning',
                    'wp_full_stripe_test_keys_missing',
                    'WP Full Stripeがテストモードですが、テスト鍵が未設定です'
                );
            }
        } else {
            if (!$this->is_set($live_pk) || !$this->is_set($live_sk)) {
                $mode_display = is_string($mode_raw) ? $mode_raw : (is_bool($mode_raw) ? ($mode_raw ? 'true' : 'false') : ($mode_raw === null ? 'なし' : (string) $mode_raw));
                $this->add_warning(
                    'notice',
                    'wp_full_stripe_live_keys_missing',
                    'WP Full Stripeで本番鍵が見つかりませんでした(モード:' . $mode_display . ')'
                );
            }
        }

        // 最終 mode 値の決定
        if ($is_test) {
            $final_mode = 'test';
        } elseif ($is_live) {
            $final_mode = 'live';
        } else {
            // mode_raw が取れなかった、または不明な値の場合、
            // 設定されている鍵から推測する (live鍵がある→live、test鍵だけ→test)
            $has_live_keys = $this->is_set($live_pk) || $this->is_set($live_sk);
            $has_test_keys = $this->is_set($test_pk) || $this->is_set($test_sk);
            if ($has_live_keys && !$has_test_keys) {
                $final_mode = 'live';
                $mode_decision .= '|inferred_from_keys_live';
            } elseif ($has_test_keys && !$has_live_keys) {
                $final_mode = 'test';
                $mode_decision .= '|inferred_from_keys_test';
            } elseif ($has_live_keys && $has_test_keys) {
                // 両方鍵があるが mode が不明 → configured (鍵はあるが現在モード不明)
                $final_mode = 'configured';
                $mode_decision .= '|both_keys_present';
            } else {
                $final_mode = 'unknown';
            }
        }

        return $this->build_response(array(
            'version'                  => $this->get_plugin_version('wp-full-stripe-free/wp-full-stripe-free.php')
                ?: $this->get_plugin_version('wp-full-stripe/wp-full-stripe.php'),
            'mode'                     => $final_mode,
            'publishable_key_set'      => $this->is_set($live_pk),
            'publishable_key_prefix'   => $this->key_prefix($live_pk, 8),
            'secret_key_set'           => $this->is_set($live_sk),
            'test_publishable_key_set' => $this->is_set($test_pk),
            'test_publishable_key_prefix' => $this->key_prefix($test_pk, 8),
            'test_secret_key_set'      => $this->is_set($test_sk),
            'webhook_configured'       => $webhook_configured,
            'live_webhook_set'         => $this->is_set($live_wh),
            'test_webhook_set'         => $this->is_set($test_wh),
            'currency'                 => is_string($currency) ? strtoupper($currency) : null,
            'settings_url'             => admin_url('admin.php?page=wpfs-settings'),
            'custom'                   => array(
                'option_name_used'      => $option_name,
                'mode_raw_value'        => $mode_raw,
                'mode_decision'         => $mode_decision,
                'addon_members_active'  => $this->is_plugin_active('wp-full-stripe-members/wp-full-stripe-members.php'),
                'addon_members_version' => $this->get_plugin_version('wp-full-stripe-members/wp-full-stripe-members.php'),
                'detected_keys'         => array_keys($settings),  // デバッグ用 (実機調査時に使う)
            ),
        ));
    }

    /**
     * 候補オプション名から最初に見つかった配列を返す
     * 文字列がserializeされている可能性も考慮する
     *
     * @return array|null  ['_option_name' => string, ...設定値] または null
     */
    private function find_settings() {
        foreach (self::OPTION_CANDIDATES as $key) {
            $val = get_option($key, false);
            if ($val === false) continue;

            if (is_array($val) && !empty($val)) {
                $val['_option_name'] = $key;
                return $val;
            }
            if (is_string($val) && strlen($val) > 0) {
                // serializeされた文字列の可能性
                $decoded = maybe_unserialize($val);
                if (is_array($decoded) && !empty($decoded)) {
                    $decoded['_option_name'] = $key;
                    return $decoded;
                }
                // JSONの可能性
                $json_decoded = json_decode($val, true);
                if (is_array($json_decoded) && !empty($json_decoded)) {
                    $json_decoded['_option_name'] = $key;
                    return $json_decoded;
                }
            }
        }
        return null;
    }
}
