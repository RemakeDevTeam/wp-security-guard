<?php
/**
 * WP Security Guard - WooCommerce Membership Inspector
 *
 * WooCommerce の商品情報を返す。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Membership_WooCommerce extends WPSG_Membership_Inspector_Base {

    /** 商品取得時の上限(大規模ECで全件取得を避ける) */
    const PRODUCTS_LIMIT = 500;

    /**
     * @inheritDoc
     */
    public function is_active() {
        return class_exists('WooCommerce');
    }

    /**
     * @inheritDoc
     */
    public function get_implementation_id() {
        return 'woocommerce';
    }

    /**
     * @inheritDoc
     */
    public function get_products() {
        if (!$this->is_active()) {
            return array();
        }
        if (!function_exists('wc_get_products') || !function_exists('get_woocommerce_currency')) {
            return array();
        }

        $currency = get_woocommerce_currency();
        $wc_products = wc_get_products(array(
            'status'  => 'publish',
            'limit'   => self::PRODUCTS_LIMIT,
            'orderby' => 'id',
            'order'   => 'ASC',
        ));

        // 件数超過の警告
        $total_count = $this->get_total_product_count();
        if ($total_count > self::PRODUCTS_LIMIT) {
            $this->add_warning(
                'notice',
                'woocommerce_products_truncated',
                sprintf(
                    'WooCommerce商品が%d件あり、最初の%d件のみ取得しました',
                    $total_count, self::PRODUCTS_LIMIT
                ),
                array('total_count' => $total_count, 'limit' => self::PRODUCTS_LIMIT)
            );
        }

        $products = array();
        foreach ((array) $wc_products as $product) {
            if (!is_object($product)) continue;
            $products[] = array(
                'id'             => 'woocommerce:' . $product->get_id(),
                'implementation' => 'woocommerce',
                'product_id'     => $product->get_id(),
                'product_type'   => $product->get_type(),
                'name'           => $product->get_name(),
                'sku'            => $product->get_sku(),
                'amount'         => (float) $product->get_price(),
                'regular_price'  => (float) $product->get_regular_price(),
                'sale_price'     => $product->get_sale_price() !== '' ? (float) $product->get_sale_price() : null,
                'currency'       => $currency,
                'in_stock'       => $product->is_in_stock(),
                'stock_quantity' => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
                'active'         => $product->get_status() === 'publish',
            );
        }
        return $products;
    }

    /**
     * @inheritDoc
     */
    public function get_metadata() {
        $version = $this->get_plugin_version('woocommerce/woocommerce.php');
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'JPY';

        return array(
            'plugin_version'    => $version,
            'currency'          => $currency,
            'product_count'     => $this->get_total_product_count(),
            'order_count'       => $this->get_order_count_total(),
            'customer_count'    => $this->get_customer_count(),
            'subscriptions_active' => class_exists('WC_Subscriptions'),
            'reviews_enabled'   => get_option('woocommerce_enable_reviews', 'yes') === 'yes',
            'ratings_enabled'   => get_option('woocommerce_enable_review_rating', 'yes') === 'yes',
            'enabled_payment_gateways' => $this->get_enabled_gateways(),
            'currency_position' => get_option('woocommerce_currency_pos', ''),
        );
    }

    // ========================================================================
    // ヘルパー
    // ========================================================================

    /**
     * 公開ステータスの商品総数
     */
    private function get_total_product_count() {
        $wpdb = $this->wpdb();
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_type = 'product' AND post_status = 'publish'"
        );
    }

    /**
     * 全注文数
     */
    private function get_order_count_total() {
        if (!function_exists('wc_orders_count')) {
            return null;
        }
        try {
            $statuses = array('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-cancelled', 'wc-refunded', 'wc-failed');
            $total = 0;
            foreach ($statuses as $status) {
                $total += (int) wc_orders_count($status);
            }
            return $total;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 顧客数(customer ロール)
     */
    private function get_customer_count() {
        if (!function_exists('count_users')) {
            return null;
        }
        $counts = count_users();
        return isset($counts['avail_roles']['customer']) ? (int) $counts['avail_roles']['customer'] : 0;
    }

    /**
     * 有効化された決済ゲートウェイ一覧
     *
     * @return array
     */
    private function get_enabled_gateways() {
        if (!class_exists('WC_Payment_Gateways')) return array();
        try {
            $gateways = WC()->payment_gateways()->payment_gateways();
            $enabled = array();
            foreach ($gateways as $gateway) {
                if (!empty($gateway->enabled) && $gateway->enabled === 'yes') {
                    $enabled[] = array(
                        'id'    => $gateway->id,
                        'title' => $gateway->method_title,
                    );
                }
            }
            return $enabled;
        } catch (Exception $e) {
            return array();
        }
    }
}
