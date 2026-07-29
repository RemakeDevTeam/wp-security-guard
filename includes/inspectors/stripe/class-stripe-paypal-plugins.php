<?php
/**
 * WP Security Guard - PayPal Plugins Inspector
 *
 * Payment Plugins for PayPal WooCommerce / WC PayPal Payments の設定を取得する。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Stripe_PayPal_Plugins extends WPSG_Stripe_Inspector_Base {

    const PLUGIN_PYMNTPLS = 'pymntpls-paypal-woocommerce/pymntpls-paypal-woocommerce.php';
    const PLUGIN_OFFICIAL = 'woocommerce-paypal-payments/woocommerce-paypal-payments.php';

    public function is_active() {
        return $this->is_plugin_active(self::PLUGIN_PYMNTPLS)
            || $this->is_plugin_active(self::PLUGIN_OFFICIAL);
    }

    public function get_implementation_id() {
        return 'paypal-plugins';
    }

    public function get_data() {
        // どのプラグインがアクティブかを判定し、それぞれのオプションを読む
        if ($this->is_plugin_active(self::PLUGIN_OFFICIAL)) {
            return $this->get_official_paypal_data();
        }
        if ($this->is_plugin_active(self::PLUGIN_PYMNTPLS)) {
            return $this->get_pymntpls_data();
        }
        return $this->build_response(array('mode' => 'unknown'));
    }

    private function get_official_paypal_data() {
        // 公式 woocommerce-paypal-payments の設定
        $settings = get_option('woocommerce-ppcp-settings', array());
        if (!is_array($settings)) $settings = array();

        $is_sandbox = ($settings['sandbox_on'] ?? false) || (($settings['env'] ?? 'production') === 'sandbox');

        $live_client_id = $this->find_value($settings, array('client_id_production', 'client_id'));
        $live_secret    = $this->find_value($settings, array('client_secret_production', 'client_secret'));
        $sandbox_client_id = $this->find_value($settings, array('client_id_sandbox'));
        $sandbox_secret    = $this->find_value($settings, array('client_secret_sandbox'));

        return $this->build_response(array(
            'version'                  => $this->get_plugin_version(self::PLUGIN_OFFICIAL),
            'enabled'                  => ($settings['enabled'] ?? 'no') === 'yes',
            'mode'                     => $is_sandbox ? 'test' : 'live',
            'publishable_key_set'      => $this->is_set($live_client_id),
            'publishable_key_prefix'   => $this->key_prefix($live_client_id, 8),
            'secret_key_set'           => $this->is_set($live_secret),
            'test_publishable_key_set' => $this->is_set($sandbox_client_id),
            'test_publishable_key_prefix' => $this->key_prefix($sandbox_client_id, 8),
            'test_secret_key_set'      => $this->is_set($sandbox_secret),
            'currency'                 => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
            'settings_url'             => admin_url('admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway'),
            'custom'                   => array(
                'plugin_used' => 'woocommerce-paypal-payments',
                'merchant_id_set' => $this->is_set($this->find_value($settings, array('merchant_id_production', 'merchant_id'))),
            ),
        ));
    }

    private function get_pymntpls_data() {
        // pymntpls-paypal-woocommerce の設定 (woocommerce_<gateway_id>_settings)
        $candidates = array(
            'woocommerce_ppcp-gateway_settings',
            'woocommerce_pymntpls-paypal_settings',
            'woocommerce_paypal_settings',
        );
        $settings = array();
        $option_name_used = null;
        foreach ($candidates as $key) {
            $val = get_option($key, false);
            if (is_array($val) && !empty($val)) {
                $settings = $val;
                $option_name_used = $key;
                break;
            }
        }

        $is_sandbox = ($settings['sandbox'] ?? $settings['testmode'] ?? 'no') === 'yes';
        $live_client_id = $this->find_value($settings, array('client_id_live', 'client_id', 'live_client_id'));
        $live_secret    = $this->find_value($settings, array('client_secret_live', 'client_secret', 'live_client_secret'));
        $sandbox_client_id = $this->find_value($settings, array('client_id_sandbox', 'sandbox_client_id'));
        $sandbox_secret    = $this->find_value($settings, array('client_secret_sandbox', 'sandbox_client_secret'));

        return $this->build_response(array(
            'version'                  => $this->get_plugin_version(self::PLUGIN_PYMNTPLS),
            'enabled'                  => ($settings['enabled'] ?? 'no') === 'yes',
            'mode'                     => $is_sandbox ? 'test' : 'live',
            'publishable_key_set'      => $this->is_set($live_client_id),
            'publishable_key_prefix'   => $this->key_prefix($live_client_id, 8),
            'secret_key_set'           => $this->is_set($live_secret),
            'test_publishable_key_set' => $this->is_set($sandbox_client_id),
            'test_publishable_key_prefix' => $this->key_prefix($sandbox_client_id, 8),
            'test_secret_key_set'      => $this->is_set($sandbox_secret),
            'currency'                 => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
            'settings_url'             => admin_url('admin.php?page=wc-settings&tab=checkout'),
            'custom'                   => array(
                'plugin_used'      => 'pymntpls-paypal-woocommerce',
                'option_name_used' => $option_name_used,
            ),
        ));
    }
}
