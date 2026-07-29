<?php
/**
 * WP Security Guard - WP Full Stripe (Themeisle) Inspector
 *
 * Themeisle版のWP Full Stripeのフォーム/プラン情報を返す。
 * テーブルプレフィックスは wp_fullstripe_*。
 * プラン詳細はREST APIで非公開のため、$wpdb で直接テーブル参照する。
 *
 * 6種類のフォームテーブルをスキャンする:
 *  - subscription_forms (recurring inline)
 *  - payment_forms (one-time inline)
 *  - donation_forms (donation inline)
 *  - checkout_subscription_forms (Stripe Checkout recurring)
 *  - checkout_payment_forms (Stripe Checkout one-time)
 *  - checkout_donation_forms (Stripe Checkout donation)
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Membership_WP_Full_Stripe extends WPSG_Membership_Inspector_Base {

    /** 旧版(Mammothology製)の検出対象ファイル */
    const LEGACY_PLUGIN_FILES = array(
        'wp-full-pay-fm-premium/wp-full-pay-fm-premium.php',
        'wp-full-pay-members/wp-full-pay-members.php',
    );

    /**
     * @inheritDoc
     */
    public function is_active() {
        // 1. プラグインの有効化状態 (各種フォルダ名・ファイル名パターンに対応)
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
        // 2. 関数 / クラス検出のフォールバック
        if (function_exists('mm_wpfs')) {
            return true;
        }
        if (class_exists('MM_WPFS')) {
            return true;
        }
        // 3. active_plugins 総当たり (wp-full-stripe-members は除外)
        $active_plugins = (array) get_option('active_plugins', array());
        if (is_multisite()) {
            $network_active = (array) get_site_option('active_sitewide_plugins', array());
            $active_plugins = array_merge($active_plugins, array_keys($network_active));
        }
        foreach ($active_plugins as $plugin_file) {
            $pf = strtolower((string) $plugin_file);
            if (strpos($pf, 'wp-full-stripe-members') !== false) {
                continue;
            }
            if (strpos($pf, 'wp-full-stripe') !== false) {
                return true;
            }
        }
        // 4. テーブル存在確認 (プラグインが無効でも残存している場合あり)
        $wpdb = $this->wpdb();
        $table = $wpdb->prefix . 'fullstripe_subscription_forms';
        return $this->table_exists($table);
    }

    /**
     * @inheritDoc
     */
    public function get_implementation_id() {
        return 'wp-full-stripe';
    }

    /**
     * @inheritDoc
     */
    public function get_products() {
        if (!$this->is_active()) {
            return array();
        }

        $products = array();
        $products = array_merge($products, $this->scan_subscription_forms());
        $products = array_merge($products, $this->scan_payment_forms());
        $products = array_merge($products, $this->scan_donation_forms());
        $products = array_merge($products, $this->scan_checkout_subscription_forms());
        $products = array_merge($products, $this->scan_checkout_payment_forms());
        $products = array_merge($products, $this->scan_checkout_donation_forms());
        return $products;
    }

    /**
     * @inheritDoc
     */
    public function get_metadata() {
        $legacy = $this->detect_legacy_plugins();
        $members_stats = $this->get_members_stats();
        $livemode_consistency = $this->check_livemode_consistency();

        if (!empty($legacy)) {
            $this->add_warning(
                'warning',
                'wp_full_stripe_legacy_residual',
                sprintf('%d件のWP Full Pay旧版プラグインが残存しています', count($legacy)),
                array('files' => array_column($legacy, 'file'))
            );
        }

        if (!empty($livemode_consistency['mixed'])) {
            $this->add_warning(
                'warning',
                'wp_full_stripe_livemode_mixed',
                'WP Full Stripeの会員データに本番モード/テストモードが混在しています',
                array(
                    'live_count' => $livemode_consistency['live_count'],
                    'test_count' => $livemode_consistency['test_count'],
                )
            );
        }

        return array(
            'plugin_version'        => $this->get_plugin_version('wp-full-stripe-free/wp-full-stripe-free.php'),
            'legacy_plugin_version' => $this->get_plugin_version('wp-full-stripe/wp-full-stripe.php'),
            'addon_active'          => $this->is_plugin_active('wp-full-stripe-members/wp-full-stripe-members.php'),
            'addon_version'         => $this->get_plugin_version('wp-full-stripe-members/wp-full-stripe-members.php'),
            'legacy_plugins'        => $legacy,
            'members_stats'         => $members_stats,
            'livemode_consistency'  => $livemode_consistency,
            'tables_present'        => $this->get_present_tables(),
        );
    }

    // ========================================================================
    // フォームテーブルスキャン
    // ========================================================================

    /**
     * subscription_forms テーブルをスキャン
     * decoratedPlans カラムの JSON を展開して複数プランに分解
     */
    protected function scan_subscription_forms() {
        $wpdb = $this->wpdb();
        $table = $wpdb->prefix . 'fullstripe_subscription_forms';
        if (!$this->table_exists($table)) return array();

        $columns = $this->get_table_columns($table);
        $select = $this->build_select_columns(
            array('subscriptionFormID', 'name'),
            array('displayName', 'formTitle', 'decoratedPlans', 'setupFee'),
            $columns
        );
        $rows = $wpdb->get_results("SELECT {$select} FROM `{$table}`", ARRAY_A);
        if (!is_array($rows)) return array();

        $products = array();
        foreach ($rows as $row) {
            $form_id   = (int) ($row['subscriptionFormID'] ?? 0);
            $form_slug = isset($row['name']) ? (string) $row['name'] : '';
            $form_name = $row['displayName'] ?? $row['formTitle'] ?? $form_slug;
            $plans_raw = $row['decoratedPlans'] ?? '[]';
            $plans = json_decode($plans_raw, true);
            if (!is_array($plans)) {
                $this->add_warning(
                    'warning',
                    'wp_full_stripe_decorated_plans_parse_error',
                    sprintf('プラン情報のJSON解析に失敗 (form_id=%d, slug=%s)', $form_id, $form_slug),
                    array('json_last_error' => json_last_error_msg())
                );
                $plans = array();
            }

            foreach ($plans as $idx => $plan) {
                if (!is_array($plan)) continue;
                $products[] = array(
                    'id'              => sprintf('subscription:%d:%s', $form_id, $plan['stripePriceId'] ?? $idx),
                    'implementation'  => 'wp-full-stripe',
                    'form_id'         => $form_id,
                    'form_slug'       => $form_slug,
                    'form_name'       => $form_name,
                    'form_type'       => 'subscription',
                    'plan_name'       => isset($plan['name']) ? (string) $plan['name'] : '',
                    'amount'          => isset($plan['price']) ? (int) $plan['price'] : null,
                    'currency'        => strtoupper($plan['currency'] ?? 'JPY'),
                    'interval'        => isset($plan['interval']) ? (string) $plan['interval'] : null,
                    'interval_count'  => (int) ($plan['intervalCount'] ?? 1),
                    'trial_days'      => (int) ($plan['trialDays'] ?? 0),
                    'setup_fee'       => (int) ($plan['setupFee'] ?? $row['setupFee'] ?? 0),
                    'stripe_price_id' => isset($plan['stripePriceId']) ? (string) $plan['stripePriceId'] : null,
                    'shortcode'       => sprintf('[fullstripe_form name="%s" type="inline_subscription"]', $form_slug),
                    'active'          => true,
                );
            }
        }
        return $products;
    }

    /**
     * payment_forms テーブルをスキャン
     */
    protected function scan_payment_forms() {
        return $this->scan_simple_form_table(
            'fullstripe_payment_forms',
            'paymentFormID',
            'payment',
            'inline_payment'
        );
    }

    /**
     * donation_forms テーブルをスキャン
     */
    protected function scan_donation_forms() {
        return $this->scan_simple_form_table(
            'fullstripe_donation_forms',
            'donationFormID',
            'donation',
            'inline_donation'
        );
    }

    /**
     * checkout_subscription_forms テーブルをスキャン
     * decoratedPlans を含む
     */
    protected function scan_checkout_subscription_forms() {
        $wpdb = $this->wpdb();
        $table = $wpdb->prefix . 'fullstripe_checkout_subscription_forms';
        if (!$this->table_exists($table)) return array();

        $columns = $this->get_table_columns($table);
        $id_col = $this->find_id_column($columns, array('checkoutSubscriptionFormID', 'formID', 'id'));
        if (!$id_col) return array();

        $select = $this->build_select_columns(
            array($id_col, 'name'),
            array('displayName', 'formTitle', 'decoratedPlans'),
            $columns
        );
        $rows = $wpdb->get_results("SELECT {$select} FROM `{$table}`", ARRAY_A);
        if (!is_array($rows)) return array();

        $products = array();
        foreach ($rows as $row) {
            $form_id   = (int) ($row[$id_col] ?? 0);
            $form_slug = isset($row['name']) ? (string) $row['name'] : '';
            $form_name = $row['displayName'] ?? $row['formTitle'] ?? $form_slug;
            $plans = json_decode($row['decoratedPlans'] ?? '[]', true);
            if (!is_array($plans)) $plans = array();

            foreach ($plans as $idx => $plan) {
                if (!is_array($plan)) continue;
                $products[] = array(
                    'id'              => sprintf('checkout-subscription:%d:%s', $form_id, $plan['stripePriceId'] ?? $idx),
                    'implementation'  => 'wp-full-stripe',
                    'form_id'         => $form_id,
                    'form_slug'       => $form_slug,
                    'form_name'       => $form_name,
                    'form_type'       => 'checkout_subscription',
                    'plan_name'       => isset($plan['name']) ? (string) $plan['name'] : '',
                    'amount'          => isset($plan['price']) ? (int) $plan['price'] : null,
                    'currency'        => strtoupper($plan['currency'] ?? 'JPY'),
                    'interval'        => isset($plan['interval']) ? (string) $plan['interval'] : null,
                    'interval_count'  => (int) ($plan['intervalCount'] ?? 1),
                    'stripe_price_id' => isset($plan['stripePriceId']) ? (string) $plan['stripePriceId'] : null,
                    'shortcode'       => sprintf('[fullstripe_form name="%s" type="checkout_subscription"]', $form_slug),
                    'active'          => true,
                );
            }
        }
        return $products;
    }

    /**
     * checkout_payment_forms テーブルをスキャン
     */
    protected function scan_checkout_payment_forms() {
        return $this->scan_simple_form_table(
            'fullstripe_checkout_payment_forms',
            null,
            'checkout_payment',
            'checkout_payment',
            array('checkoutPaymentFormID', 'formID', 'id')
        );
    }

    /**
     * checkout_donation_forms テーブルをスキャン
     */
    protected function scan_checkout_donation_forms() {
        return $this->scan_simple_form_table(
            'fullstripe_checkout_donation_forms',
            null,
            'checkout_donation',
            'checkout_donation',
            array('checkoutDonationFormID', 'formID', 'id')
        );
    }

    /**
     * 単純構造のフォームテーブル(amount/currency直接保持型)をスキャン
     *
     * @param string $table_suffix プレフィックスを除いたテーブル名
     * @param string|null $id_col_explicit 主キーカラム名(nullなら$id_candidatesから探す)
     * @param string $form_type form_type 値
     * @param string $shortcode_type ショートコード種別
     * @param array  $id_candidates id_col_explicitがnullの時に使用
     * @return array
     */
    protected function scan_simple_form_table($table_suffix, $id_col_explicit, $form_type, $shortcode_type, $id_candidates = array()) {
        $wpdb = $this->wpdb();
        $table = $wpdb->prefix . $table_suffix;
        if (!$this->table_exists($table)) return array();

        $columns = $this->get_table_columns($table);
        $id_col = $id_col_explicit ?: $this->find_id_column($columns, $id_candidates);
        if (!$id_col || !in_array($id_col, $columns, true)) {
            $this->add_warning(
                'notice',
                'wp_full_stripe_id_column_unknown',
                sprintf('テーブル %s のID列が判定できませんでした', $table_suffix),
                array('columns' => $columns)
            );
            return array();
        }

        $select_cols = array($id_col, 'name');
        foreach (array('displayName', 'formTitle', 'amount', 'currency', 'price') as $col) {
            if (in_array($col, $columns, true)) {
                $select_cols[] = $col;
            }
        }
        $select = '`' . implode('`, `', $select_cols) . '`';
        $rows = $wpdb->get_results("SELECT {$select} FROM `{$table}`", ARRAY_A);
        if (!is_array($rows)) return array();

        $products = array();
        foreach ($rows as $row) {
            $form_id = (int) ($row[$id_col] ?? 0);
            $form_slug = isset($row['name']) ? (string) $row['name'] : '';
            $form_name = $row['displayName'] ?? $row['formTitle'] ?? $form_slug;
            $amount = null;
            if (isset($row['amount'])) {
                $amount = (int) $row['amount'];
            } elseif (isset($row['price'])) {
                $amount = (int) $row['price'];
            }
            $currency = isset($row['currency']) ? strtoupper((string) $row['currency']) : 'JPY';
            $products[] = array(
                'id'             => sprintf('%s:%d', $form_type, $form_id),
                'implementation' => 'wp-full-stripe',
                'form_id'        => $form_id,
                'form_slug'      => $form_slug,
                'form_name'      => $form_name,
                'form_type'      => $form_type,
                'plan_name'      => null,
                'amount'         => $amount,
                'currency'       => $currency,
                'interval'       => null,
                'shortcode'      => sprintf('[fullstripe_form name="%s" type="%s"]', $form_slug, $shortcode_type),
                'active'         => true,
            );
        }
        return $products;
    }

    // ========================================================================
    // メタデータ用ヘルパー
    // ========================================================================

    /**
     * 旧版(Mammothology製)の残存検出
     *
     * @return array
     */
    protected function detect_legacy_plugins() {
        $legacy = array();
        foreach (self::LEGACY_PLUGIN_FILES as $file) {
            $path = WP_PLUGIN_DIR . '/' . $file;
            if (file_exists($path)) {
                $legacy[] = array(
                    'file'    => $file,
                    'active'  => $this->is_plugin_active($file),
                    'version' => $this->get_plugin_version($file),
                    'warning' => '旧版(Mammothology製)が残存しています。削除を推奨',
                );
            }
        }
        return $legacy;
    }

    /**
     * members テーブルの統計
     *
     * @return array
     */
    protected function get_members_stats() {
        $wpdb = $this->wpdb();
        $table = $wpdb->prefix . 'fullstripe_members';
        if (!$this->table_exists($table)) {
            return array(
                'available' => false,
                'total'     => 0,
            );
        }
        $columns = $this->get_table_columns($table);
        $has_livemode = in_array('livemode', $columns, true);
        $has_status   = in_array('stripeSubscriptionStatus', $columns, true);

        $stats = array(
            'available' => true,
            'total'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"),
        );
        if ($has_livemode) {
            $stats['live_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE livemode = 1");
            $stats['test_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE livemode = 0");
        }
        if ($has_status) {
            $by_status = $wpdb->get_results(
                "SELECT stripeSubscriptionStatus AS status, COUNT(*) AS count 
                 FROM `{$table}` 
                 GROUP BY stripeSubscriptionStatus",
                ARRAY_A
            );
            $stats['by_status'] = is_array($by_status) ? $by_status : array();
        }
        return $stats;
    }

    /**
     * livemode の混在チェック
     *
     * @return array ['mixed' => bool, 'live_count' => int, 'test_count' => int, 'warning' => string|null]
     */
    protected function check_livemode_consistency() {
        $wpdb = $this->wpdb();
        $table = $wpdb->prefix . 'fullstripe_members';
        if (!$this->table_exists($table)) {
            return array('mixed' => false, 'live_count' => 0, 'test_count' => 0, 'warning' => null);
        }
        $columns = $this->get_table_columns($table);
        if (!in_array('livemode', $columns, true)) {
            return array('mixed' => false, 'live_count' => 0, 'test_count' => 0, 'warning' => null);
        }
        $live = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE livemode = 1");
        $test = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE livemode = 0");
        $mixed = $live > 0 && $test > 0;
        return array(
            'mixed'      => $mixed,
            'live_count' => $live,
            'test_count' => $test,
            'warning'    => $mixed ? '本番モード/テストモードの会員が混在しています' : null,
        );
    }

    /**
     * 存在する fullstripe_* テーブルの一覧
     */
    protected function get_present_tables() {
        $wpdb = $this->wpdb();
        $tables = array(
            'subscription_forms', 'payment_forms', 'donation_forms',
            'checkout_subscription_forms', 'checkout_payment_forms', 'checkout_donation_forms',
            'members', 'subscribers', 'payments',
        );
        $present = array();
        foreach ($tables as $suffix) {
            $full = $wpdb->prefix . 'fullstripe_' . $suffix;
            if ($this->table_exists($full)) {
                $present[] = $suffix;
            }
        }
        return $present;
    }

    /**
     * SELECT カラム文字列の組み立て
     * 必須カラム + (存在する場合に追加するカラム) を構成
     */
    private function build_select_columns(array $required, array $optional, array $available_columns) {
        $cols = array_intersect($required, $available_columns);
        foreach ($optional as $col) {
            if (in_array($col, $available_columns, true)) {
                $cols[] = $col;
            }
        }
        $cols = array_values(array_unique($cols));
        return '`' . implode('`, `', $cols) . '`';
    }

    /**
     * ID列の特定 (候補から選ぶ)
     */
    private function find_id_column(array $columns, array $candidates) {
        foreach ($candidates as $cand) {
            if (in_array($cand, $columns, true)) {
                return $cand;
            }
        }
        return null;
    }
}
