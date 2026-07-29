<?php
/**
 * WP Security Guard - Stripe Dispatcher
 *
 * 全Stripe inspectorを実行し、結果を統合する。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Stripe_Dispatcher {

    /** @var WPSG_Stripe_Inspector_Base[] */
    private $inspectors = array();

    /** @var array */
    private $warnings = array();

    public function __construct() {
        $this->inspectors = array(
            new WPSG_Stripe_WC_Gateway(),
            new WPSG_Stripe_WC_Vendors_Connect(),
            new WPSG_Stripe_WP_Full_Stripe(),
            new WPSG_Stripe_PayPal_Plugins(),
            new WPSG_Stripe_BankPay(),
        );
        $this->inspectors = apply_filters('wpsg_inspect_stripe_inspectors', $this->inspectors);
    }

    /**
     * 全インスペクター実行
     *
     * @return array
     */
    public function inspect_all() {
        $result = array(
            'implementations' => array(),
            'details'         => array(),
        );

        foreach ($this->inspectors as $inspector) {
            if (!($inspector instanceof WPSG_Stripe_Inspector_Base)) continue;
            try {
                if ($inspector->is_active()) {
                    $impl_id = $inspector->get_implementation_id();
                    $result['implementations'][] = $impl_id;
                    $data = $inspector->get_data();
                    $result['details'][$impl_id] = is_array($data) ? $data : array();
                }
                // 警告はactive/inactiveに関わらず収集
                foreach ($inspector->get_warnings() as $w) {
                    $this->warnings[] = $w;
                }
            } catch (Exception $e) {
                $this->warnings[] = array(
                    'level'   => 'error',
                    'code'    => 'stripe_inspector_exception',
                    'message' => sprintf('%s: %s', get_class($inspector), $e->getMessage()),
                );
            }
        }

        // モード一貫性チェック
        $result['mode_consistency'] = $this->check_mode_consistency($result['details']);
        $result['summary'] = $this->build_summary($result);

        return $result;
    }

    public function get_warnings() {
        return $this->warnings;
    }

    /**
     * 複数の決済実装が同居している場合のモード一貫性チェック
     * 例: WC StripeはLive、WP Full StripeはTest → 警告
     */
    private function check_mode_consistency(array $details) {
        $modes = array();
        foreach ($details as $impl_id => $data) {
            $mode = isset($data['mode']) ? $data['mode'] : 'unknown';
            // 'configured'/'unconfigured'/'unknown' は除外 (BankPay や未設定)
            if (in_array($mode, array('live', 'test'), true)) {
                $modes[$impl_id] = $mode;
            }
        }
        if (count($modes) <= 1) {
            return array(
                'consistent' => true,
                'modes'      => $modes,
                'warning'    => null,
            );
        }
        $unique = array_unique(array_values($modes));
        if (count($unique) > 1) {
            $this->warnings[] = array(
                'level'   => 'warning',
                'code'    => 'stripe_mode_inconsistent',
                'message' => '複数の決済実装でモード(本番/テスト)が一致していません',
                'details' => array('modes' => $modes),
            );
            return array(
                'consistent' => false,
                'modes'      => $modes,
                'warning'    => '複数の決済実装でモードが不一致',
            );
        }
        return array(
            'consistent' => true,
            'modes'      => $modes,
            'warning'    => null,
        );
    }

    private function build_summary(array $result) {
        $details = isset($result['details']) ? $result['details'] : array();
        $live_count = 0;
        $test_count = 0;
        $configured_count = 0;
        $missing_keys_count = 0;
        $webhooks_configured = 0;

        foreach ($details as $impl_id => $data) {
            $mode = $data['mode'] ?? 'unknown';
            if ($mode === 'live') $live_count++;
            if ($mode === 'test') $test_count++;
            if ($mode === 'configured') $configured_count++;

            $is_test = $mode === 'test';
            $pk_set = $is_test ? !empty($data['test_publishable_key_set']) : !empty($data['publishable_key_set']);
            $sk_set = $is_test ? !empty($data['test_secret_key_set']) : !empty($data['secret_key_set']);
            if (!$pk_set || !$sk_set) {
                if (in_array($mode, array('live', 'test'), true)) {
                    $missing_keys_count++;
                }
            }
            if (!empty($data['webhook_configured'])) {
                $webhooks_configured++;
            }
        }

        return array(
            'implementations_count' => count($details),
            'live_implementations'  => $live_count,
            'test_implementations'  => $test_count,
            'configured_only'       => $configured_count,
            'missing_keys_count'    => $missing_keys_count,
            'webhooks_configured'   => $webhooks_configured,
        );
    }
}
