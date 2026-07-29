<?php
/**
 * WP Security Guard - WooCommerce Stripe Gateway Inspector
 *
 * WooCommerce 公式 Stripe Gateway プラグインの設定を取得する。
 * オプションキー: woocommerce_stripe_settings
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Stripe_WC_Gateway extends WPSG_Stripe_Inspector_Base {

    const PLUGIN_FILE = 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php';

    public function is_active() {
        return $this->is_plugin_active(self::PLUGIN_FILE);
    }

    public function get_implementation_id() {
        return 'wc-stripe-gateway';
    }

    public function get_data() {
        $settings = get_option('woocommerce_stripe_settings', array());
        if (!is_array($settings)) {
            $settings = array();
        }

        $enabled = ($settings['enabled'] ?? 'no') === 'yes';
        $is_test = ($settings['testmode'] ?? 'no') === 'yes';

        $live_pk = isset($settings['publishable_key']) ? (string) $settings['publishable_key'] : '';
        $live_sk = isset($settings['secret_key']) ? (string) $settings['secret_key'] : '';
        $test_pk = isset($settings['test_publishable_key']) ? (string) $settings['test_publishable_key'] : '';
        $test_sk = isset($settings['test_secret_key']) ? (string) $settings['test_secret_key'] : '';
        $live_wh = isset($settings['webhook_secret']) ? (string) $settings['webhook_secret'] : '';
        $test_wh = isset($settings['test_webhook_secret']) ? (string) $settings['test_webhook_secret'] : '';

        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null;

        // 現在モードに応じたWebhook設定有無
        $webhook_configured = $is_test ? $this->is_set($test_wh) : $this->is_set($live_wh);

        // 現在モードに応じた鍵の整合性チェック
        if ($is_test) {
            if (!$this->is_set($test_pk) || !$this->is_set($test_sk)) {
                $this->add_warning(
                    'warning',
                    'wc_stripe_test_keys_missing',
                    'WooCommerce Stripe Gatewayがテストモードですが、テスト鍵が未設定です'
                );
            }
        } else {
            if (!$this->is_set($live_pk) || !$this->is_set($live_sk)) {
                $this->add_warning(
                    'warning',
                    'wc_stripe_live_keys_missing',
                    'WooCommerce Stripe Gatewayが本番モードですが、本番鍵が未設定です'
                );
            }
        }

        return $this->build_response(array(
            'version'                  => $this->get_plugin_version(self::PLUGIN_FILE),
            'enabled'                  => $enabled,
            'mode'                     => $is_test ? 'test' : 'live',
            'publishable_key_set'      => $this->is_set($live_pk),
            'publishable_key_prefix'   => $this->key_prefix($live_pk, 8),
            'secret_key_set'           => $this->is_set($live_sk),
            'test_publishable_key_set' => $this->is_set($test_pk),
            'test_publishable_key_prefix' => $this->key_prefix($test_pk, 8),
            'test_secret_key_set'      => $this->is_set($test_sk),
            'webhook_configured'       => $webhook_configured,
            'live_webhook_set'         => $this->is_set($live_wh),
            'test_webhook_set'         => $this->is_set($test_wh),
            'currency'                 => $currency,
            'settings_url'             => admin_url('admin.php?page=wc-settings&tab=checkout&section=stripe'),
            'custom'                   => array(
                'capture'              => isset($settings['capture']) ? (string) $settings['capture'] : null,
                'statement_descriptor' => isset($settings['statement_descriptor']) ? (string) $settings['statement_descriptor'] : null,
                'inline_cc_form'       => isset($settings['inline_cc_form']) ? (string) $settings['inline_cc_form'] : null,
                'three_d_secure'       => isset($settings['three_d_secure']) ? (string) $settings['three_d_secure'] : null,
            ),
        ));
    }
}
