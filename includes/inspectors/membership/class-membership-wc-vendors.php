<?php
/**
 * WP Security Guard - WC Vendors Membership Inspector
 *
 * WC Vendorsのコミッション設定とベンダー情報を返す。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Membership_WC_Vendors extends WPSG_Membership_Inspector_Base {

    /**
     * @inheritDoc
     */
    public function is_active() {
        return class_exists('WC_Vendors')
            || $this->is_plugin_active('wc-vendors/class-wc-vendors.php');
    }

    /**
     * @inheritDoc
     */
    public function get_implementation_id() {
        return 'wc-vendors';
    }

    /**
     * @inheritDoc
     */
    public function get_products() {
        if (!$this->is_active()) {
            return array();
        }

        $products = array();

        // デフォルトコミッション率
        $default_rate = get_option('wcvendors_vendor_commission_rate', '');
        if ($default_rate !== '') {
            $products[] = array(
                'id'             => 'wc-vendors:default-commission',
                'implementation' => 'wc-vendors',
                'product_type'   => 'commission',
                'name'           => 'デフォルトコミッション率',
                'amount'         => is_numeric($default_rate) ? (float) $default_rate : 0,
                'unit'           => 'percent',
                'currency'       => null,
                'note'           => 'ベンダー手数料率(%) - 個別ベンダー単位の設定はuser_metaに保存される',
            );
        }

        return $products;
    }

    /**
     * @inheritDoc
     */
    public function get_metadata() {
        $vendor_count = $this->count_vendors();

        return array(
            'plugin_version'      => $this->get_plugin_version('wc-vendors/class-wc-vendors.php'),
            'pro_active'          => $this->is_plugin_active('wc-vendors-pro/wcvendors-pro.php'),
            'pro_version'         => $this->get_plugin_version('wc-vendors-pro/wcvendors-pro.php'),
            'stripe_connect_active' => $this->is_plugin_active('wc-vendors-stripe-connect/wc-vendors-stripe-connect.php'),
            'stripe_connect_version'=> $this->get_plugin_version('wc-vendors-stripe-connect/wc-vendors-stripe-connect.php'),
            'vendor_count'        => $vendor_count,
            'pending_vendor_count'=> $this->count_pending_vendors(),
            'commission_due_count'=> $this->count_commission_records(),
            'vendor_role_keys'    => $this->detect_vendor_role_keys(),
        );
    }

    // ========================================================================
    // ヘルパー
    // ========================================================================

    /**
     * 承認済みベンダー数(role: vendor または、検出した代替ロール)
     */
    private function count_vendors() {
        if (!function_exists('count_users')) {
            return 0;
        }
        $counts = count_users();
        $avail = isset($counts['avail_roles']) ? $counts['avail_roles'] : array();
        $total = 0;
        foreach ($this->detect_vendor_role_keys() as $role) {
            if (isset($avail[$role])) {
                $total += (int) $avail[$role];
            }
        }
        return $total;
    }

    /**
     * 申請中(pending_vendor)ベンダー数
     */
    private function count_pending_vendors() {
        if (!function_exists('count_users')) return 0;
        $counts = count_users();
        $avail = isset($counts['avail_roles']) ? $counts['avail_roles'] : array();
        return isset($avail['pending_vendor']) ? (int) $avail['pending_vendor'] : 0;
    }

    /**
     * コミッションレコード数(WC Vendorsの専用テーブルがある場合)
     */
    private function count_commission_records() {
        $wpdb = $this->wpdb();
        $table = $wpdb->prefix . 'pv_commission';
        if (!$this->table_exists($table)) {
            return null;
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    /**
     * ベンダーロールキーの検出
     *
     * @return array
     */
    private function detect_vendor_role_keys() {
        $candidates = array('vendor', 'wcv_vendor', 'shop_vendor', 'pending_vendor');
        if (!function_exists('wp_roles')) return array('vendor');
        $existing = array_keys(wp_roles()->roles ?? array());
        $detected = array();
        foreach ($candidates as $key) {
            if ($key === 'pending_vendor') continue; // pendingは別カウント
            if (in_array($key, $existing, true)) {
                $detected[] = $key;
            }
        }
        return $detected;
    }
}
