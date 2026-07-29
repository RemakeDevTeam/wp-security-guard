<?php
/**
 * WP Security Guard - BankPay Payment Inspector
 *
 * BankPayはStripeを使わない銀行振込決済プラグイン。
 * 「決済モード」概念がないため、振込先設定の有無を返す。
 * 機微な口座情報は絶対に返さない。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Stripe_BankPay extends WPSG_Stripe_Inspector_Base {

    public function is_active() {
        return defined('BKP_PLUGIN_VER')
            || $this->is_plugin_active('bankpay/bankpay.php')
            || $this->is_plugin_active('bankpay-flexible-billing-cycle/bankpay.php');
    }

    public function get_implementation_id() {
        return 'bankpay';
    }

    public function get_data() {
        $version = defined('BKP_PLUGIN_VER') ? BKP_PLUGIN_VER : null;
        if ($version === null) {
            $version = $this->get_plugin_version('bankpay/bankpay.php');
        }

        // 振込先口座設定の有無のみ判定 (中身は機微情報のため絶対返さない)
        $account_name = (string) get_option('bkp_op_account_name', '');
        $account_number = (string) get_option('bkp_op_account_number', '');
        $bank_name = (string) get_option('bkp_op_bank_name', '');
        $branch_name = (string) get_option('bkp_op_branch_name', '');

        $all_set = $this->is_set($account_name)
            && $this->is_set($account_number)
            && $this->is_set($bank_name)
            && $this->is_set($branch_name);

        $any_set = $this->is_set($account_name)
            || $this->is_set($account_number)
            || $this->is_set($bank_name)
            || $this->is_set($branch_name);

        if (!$all_set && $any_set) {
            $this->add_warning(
                'warning',
                'bankpay_account_partial',
                'BankPayの振込先口座情報が一部未設定です'
            );
        }

        return $this->build_response(array(
            'version'              => $version,
            'enabled'              => $this->is_active(),
            'mode'                 => $all_set ? 'configured' : 'unconfigured',
            // BankPayはStripe鍵を使わないため全てfalse固定
            'publishable_key_set'  => false,
            'secret_key_set'       => false,
            'test_publishable_key_set' => false,
            'test_secret_key_set'  => false,
            'webhook_configured'   => false,
            'test_mode_switchable' => false,
            'currency'             => 'JPY',
            'settings_url'         => admin_url('admin.php?page=bankpay'),
            'custom'               => array(
                'payment_method'      => 'bank_transfer',
                'account_name_set'    => $this->is_set($account_name),
                'account_number_set'  => $this->is_set($account_number),
                'bank_name_set'       => $this->is_set($bank_name),
                'branch_name_set'     => $this->is_set($branch_name),
                'all_account_info_set'=> $all_set,
                // 機微情報の中身は一切含めない
            ),
        ));
    }
}
