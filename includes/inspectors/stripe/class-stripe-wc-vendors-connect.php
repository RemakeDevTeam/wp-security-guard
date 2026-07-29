<?php
/**
 * WP Security Guard - WC Vendors Stripe Connect Inspector
 *
 * WC Vendors のStripe Connect設定を取得する。
 * Connect ApplicationIDはマスク、ベンダーアカウントIDは個別ベンダーごと(返さない)。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Stripe_WC_Vendors_Connect extends WPSG_Stripe_Inspector_Base {

    const PLUGIN_FILE = 'wc-vendors-stripe-connect/wc-vendors-stripe-connect.php';

    /** Stripe Connect設定オプション名候補 */
    const OPTION_CANDIDATES = array(
        'wcv_stripe_connect_settings',
        'wcv-stripe-connect_settings',
        'wcv_stripe_settings',
    );

    public function is_active() {
        return $this->is_plugin_active(self::PLUGIN_FILE);
    }

    public function get_implementation_id() {
        return 'wc-vendors-stripe-connect';
    }

    public function get_data() {
        $settings = $this->find_settings();

        if ($settings === null) {
            $this->add_warning(
                'notice',
                'wc_vendors_stripe_connect_options_not_found',
                'WC Vendors Stripe Connectの設定オプションが見つかりませんでした',
                array('tried_options' => self::OPTION_CANDIDATES)
            );
            return $this->build_response(array(
                'version' => $this->get_plugin_version(self::PLUGIN_FILE),
                'mode'    => 'unknown',
                'custom'  => array('option_name_used' => null),
            ));
        }

        $option_name = $settings['_option_name'];
        unset($settings['_option_name']);

        $is_test = ($settings['testmode'] ?? $settings['test_mode'] ?? 'no') === 'yes';

        $live_pk = $this->find_value($settings, array('publishable_key', 'live_publishable_key', 'pk_live'));
        $live_sk = $this->find_value($settings, array('secret_key', 'live_secret_key', 'sk_live'));
        $test_pk = $this->find_value($settings, array('test_publishable_key'));
        $test_sk = $this->find_value($settings, array('test_secret_key'));
        $client_id = $this->find_value($settings, array('client_id', 'connect_client_id', 'application_id'));
        $live_wh = $this->find_value($settings, array('webhook_secret'));
        $test_wh = $this->find_value($settings, array('test_webhook_secret'));

        return $this->build_response(array(
            'version'                  => $this->get_plugin_version(self::PLUGIN_FILE),
            'enabled'                  => ($settings['enabled'] ?? 'no') === 'yes',
            'mode'                     => $is_test ? 'test' : 'live',
            'publishable_key_set'      => $this->is_set($live_pk),
            'publishable_key_prefix'   => $this->key_prefix($live_pk, 8),
            'secret_key_set'           => $this->is_set($live_sk),
            'test_publishable_key_set' => $this->is_set($test_pk),
            'test_publishable_key_prefix' => $this->key_prefix($test_pk, 8),
            'test_secret_key_set'      => $this->is_set($test_sk),
            'webhook_configured'       => $is_test ? $this->is_set($test_wh) : $this->is_set($live_wh),
            'live_webhook_set'         => $this->is_set($live_wh),
            'test_webhook_set'         => $this->is_set($test_wh),
            'currency'                 => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
            'settings_url'             => admin_url('admin.php?page=wc-settings&tab=checkout&section=stripe-connect'),
            'custom'                   => array(
                'option_name_used'    => $option_name,
                'connect_application_id_set'    => $this->is_set($client_id),
                'connect_application_id_prefix' => $this->key_prefix($client_id, 8),
                'commission_rate'     => isset($settings['commission_rate']) ? (float) $settings['commission_rate'] : null,
            ),
        ));
    }

    private function find_settings() {
        foreach (self::OPTION_CANDIDATES as $key) {
            $val = get_option($key, false);
            if (is_array($val) && !empty($val)) {
                $val['_option_name'] = $key;
                return $val;
            }
        }
        return null;
    }
}
