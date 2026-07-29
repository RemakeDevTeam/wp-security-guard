<?php
/**
 * WP Security Guard - Membership Dispatcher
 *
 * 全会員管理inspectorを実行し、結果を統合する。
 * 1サイトで複数の実装(UM + WP Full Stripe + BankPay 等)が同居する場合に対応。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Membership_Dispatcher {

    /** @var WPSG_Membership_Inspector_Base[] */
    private $inspectors = array();

    /** @var array */
    private $warnings = array();

    public function __construct() {
        $this->inspectors = array(
            new WPSG_Membership_Ultimate_Member(),
            new WPSG_Membership_WP_Full_Stripe(),
            new WPSG_Membership_WooCommerce(),
            new WPSG_Membership_WC_Vendors(),
            new WPSG_Membership_WP_Crowdfunding(),
            new WPSG_Membership_BankPay(),
        );

        // 拡張用: 外部からinspectorを追加可能にする
        $this->inspectors = apply_filters('wpsg_inspect_membership_inspectors', $this->inspectors);
    }

    /**
     * 全アクティブinspectorを実行し、統合結果を返す
     *
     * @return array
     */
    public function inspect_all() {
        $result = array(
            'implementations' => array(),
            'products'        => array(),
            'metadata'        => array(),
        );

        foreach ($this->inspectors as $inspector) {
            if (!($inspector instanceof WPSG_Membership_Inspector_Base)) {
                continue;
            }
            try {
                $is_active = $inspector->is_active();
                if ($is_active) {
                    $impl_id = $inspector->get_implementation_id();
                    $result['implementations'][] = $impl_id;

                    $products = $inspector->get_products();
                    if (is_array($products)) {
                        $result['products'] = array_merge($result['products'], $products);
                    }

                    $metadata = $inspector->get_metadata();
                    $result['metadata'][$impl_id] = is_array($metadata) ? $metadata : array();
                }
                // 警告は active/inactive 問わず収集 (例: 旧版残存検出は無効でも報告すべき)
                foreach ($inspector->get_warnings() as $w) {
                    $this->warnings[] = $w;
                }
            } catch (Exception $e) {
                $this->warnings[] = array(
                    'level'   => 'error',
                    'code'    => 'membership_inspector_exception',
                    'message' => sprintf(
                        '%s で例外が発生しました: %s',
                        get_class($inspector),
                        $e->getMessage()
                    ),
                    'details' => array(
                        'inspector' => get_class($inspector),
                    ),
                );
            }
        }

        $result['summary'] = $this->build_summary($result);
        return $result;
    }

    /**
     * 蓄積された警告を取得
     *
     * @return array
     */
    public function get_warnings() {
        return $this->warnings;
    }

    /**
     * 統計情報を構築
     */
    private function build_summary(array $result) {
        $products = isset($result['products']) ? $result['products'] : array();

        $by_type = array();
        $currencies = array();
        $has_subscription = false;
        $total_price_sum = 0;

        foreach ($products as $p) {
            $type = isset($p['product_type']) ? $p['product_type'] : 'unknown';
            $by_type[$type] = isset($by_type[$type]) ? $by_type[$type] + 1 : 1;

            if (!empty($p['currency'])) {
                $currencies[] = strtoupper($p['currency']);
            }
            if (!empty($p['interval'])) {
                $has_subscription = true;
            }
            if (isset($p['amount']) && is_numeric($p['amount'])) {
                $total_price_sum += (float) $p['amount'];
            }
        }

        return array(
            'total_products'        => count($products),
            'implementations_count' => count($result['implementations']),
            'has_subscriptions'     => $has_subscription,
            'currencies'            => array_values(array_unique($currencies)),
            'products_by_type'      => $by_type,
            'total_price_sum'       => $total_price_sum,
        );
    }
}
