<?php
/**
 * WP Security Guard - BankPay Membership Inspector
 *
 * 自作銀行振込決済プラグイン BankPay (v2.0.2) の会費プラン情報を返す。
 *
 * 主要オプション:
 *   bkp_op_monthly_fee      月会費
 *   bkp_op_yearly_fee       年会費
 *   bkp_op_entrance_fee     入会金
 *   bkp_op_entrance_flag    入会金徴収有無
 *   bkp_op_tax_rate         税率
 *   bkp_op_paid_member_roles    有料会員ロール
 *   bkp_op_free_charge_member_roles 無料会員ロール
 *   bkp_op_account_*        振込先口座情報(機微情報・マスク必須)
 *
 * 独自テーブル:
 *   wp_bkp_user_info        会員ごとの会費・状態
 *   wp_bkp_paid_info        支払履歴
 *   wp_bkp_user_status_log  状態変更ログ
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Membership_BankPay extends WPSG_Membership_Inspector_Base {

    /** ステータス分類 (status カラムの想定値) */
    const STATUS_PAID      = array('normal', 'renewal');
    const STATUS_SUSPENDED = array('suspended');

    /**
     * @inheritDoc
     */
    public function is_active() {
        return defined('BKP_PLUGIN_VER')
            || $this->is_plugin_active('bankpay/bankpay.php')
            || $this->is_plugin_active('bankpay-flexible-billing-cycle/bankpay.php');
    }

    /**
     * @inheritDoc
     */
    public function get_implementation_id() {
        return 'bankpay';
    }

    /**
     * @inheritDoc
     */
    public function get_products() {
        if (!$this->is_active()) {
            return array();
        }

        $products = array();

        $monthly_fee   = (int) get_option('bkp_op_monthly_fee', 0);
        $yearly_fee    = (int) get_option('bkp_op_yearly_fee', 0);
        $entrance_flag = $this->get_bool_option('bkp_op_entrance_flag', false);
        $entrance_fee  = (int) get_option('bkp_op_entrance_fee', 0);
        $tax_rate      = (int) get_option('bkp_op_tax_rate', 0);

        // 月額プラン
        if ($monthly_fee > 0) {
            $products[] = array(
                'id'              => 'bankpay:monthly',
                'implementation'  => 'bankpay',
                'product_type'    => 'subscription',
                'name'            => '月会費プラン',
                'amount'          => $monthly_fee,
                'currency'        => 'JPY',
                'interval'        => 'month',
                'interval_count'  => 1,
                'entrance_fee'    => $entrance_flag ? $entrance_fee : 0,
                'tax_rate_percent'=> $tax_rate,
                'payment_method'  => 'bank_transfer',
                'active'          => true,
            );
        }

        // 年額プラン
        if ($yearly_fee > 0) {
            $products[] = array(
                'id'              => 'bankpay:yearly',
                'implementation'  => 'bankpay',
                'product_type'    => 'subscription',
                'name'            => '年会費プラン',
                'amount'          => $yearly_fee,
                'currency'        => 'JPY',
                'interval'        => 'year',
                'interval_count'  => 1,
                'entrance_fee'    => $entrance_flag ? $entrance_fee : 0,
                'tax_rate_percent'=> $tax_rate,
                'payment_method'  => 'bank_transfer',
                'active'          => true,
            );
        }

        // 入会金単独 (月額/年額が0でも入会金だけある場合は出力)
        if ($monthly_fee === 0 && $yearly_fee === 0 && $entrance_flag && $entrance_fee > 0) {
            $products[] = array(
                'id'              => 'bankpay:entrance-only',
                'implementation'  => 'bankpay',
                'product_type'    => 'one_time',
                'name'            => '入会金',
                'amount'          => $entrance_fee,
                'currency'        => 'JPY',
                'interval'        => null,
                'tax_rate_percent'=> $tax_rate,
                'payment_method'  => 'bank_transfer',
                'active'          => true,
            );
        }

        return $products;
    }

    /**
     * @inheritDoc
     */
    public function get_metadata() {
        $version = defined('BKP_PLUGIN_VER') ? BKP_PLUGIN_VER : null;
        if ($version === null) {
            $version = $this->get_plugin_version('bankpay/bankpay.php');
        }

        $paid_roles = get_option('bkp_op_paid_member_roles', array());
        $free_roles = get_option('bkp_op_free_charge_member_roles', array());

        // 振込先口座情報の有無のみを返す(中身は機微情報のためマスク)
        $has_bank_account_set = !empty(get_option('bkp_op_account_number', ''))
            && !empty(get_option('bkp_op_account_name', ''));

        return array(
            'plugin_version'        => $version,
            'tax_rate_percent'      => (int) get_option('bkp_op_tax_rate', 0),
            'billing_cycle'         => (string) get_option('bkp_op_billing_cycle', ''),
            'paid_member_roles'     => is_array($paid_roles) ? array_values($paid_roles) : array(),
            'free_member_roles'     => is_array($free_roles) ? array_values($free_roles) : array(),
            'entrance_flag'         => $this->get_bool_option('bkp_op_entrance_flag', false),
            'paid_users_count'      => $this->count_users_by_status(self::STATUS_PAID),
            'unpaid_users_count'    => $this->count_users_by_unpaid_flag(),
            'suspended_users_count' => $this->count_users_by_status(self::STATUS_SUSPENDED),
            'has_bank_account_set'  => $has_bank_account_set,
            'paid_history_count'    => $this->get_paid_info_count(),
            'tables_present'        => $this->get_present_tables(),
            // 振込先口座の中身(account_name/number等)は機微情報のため返さない
        );
    }

    // ========================================================================
    // ヘルパー
    // ========================================================================

    /**
     * 指定ステータスの会員数
     *
     * @param array $statuses
     * @return int
     */
    private function count_users_by_status(array $statuses) {
        if (empty($statuses)) return 0;
        $wpdb = $this->wpdb();
        $table = $wpdb->prefix . 'bkp_user_info';
        if (!$this->table_exists($table)) {
            return 0;
        }
        $columns = $this->get_table_columns($table);
        if (!in_array('status', $columns, true)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE status IN ({$placeholders})",
            $statuses
        );
        return (int) $wpdb->get_var($sql);
    }

    /**
     * unpaid_flag = 1 の会員数
     */
    private function count_users_by_unpaid_flag() {
        $wpdb = $this->wpdb();
        $table = $wpdb->prefix . 'bkp_user_info';
        if (!$this->table_exists($table)) {
            return 0;
        }
        $columns = $this->get_table_columns($table);
        if (!in_array('unpaid_flag', $columns, true)) {
            return 0;
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE unpaid_flag = 1");
    }

    /**
     * 支払履歴件数
     */
    private function get_paid_info_count() {
        $wpdb = $this->wpdb();
        $table = $wpdb->prefix . 'bkp_paid_info';
        if (!$this->table_exists($table)) {
            return 0;
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    /**
     * 存在する bkp_* テーブル一覧
     */
    private function get_present_tables() {
        $wpdb = $this->wpdb();
        $tables = array('user_info', 'paid_info', 'user_status_log');
        $present = array();
        foreach ($tables as $suffix) {
            $full = $wpdb->prefix . 'bkp_' . $suffix;
            if ($this->table_exists($full)) {
                $present[] = $suffix;
            }
        }
        return $present;
    }
}
