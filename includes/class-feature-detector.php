<?php
/**
 * WP Security Guard - Feature Detector
 *
 * 各機能の自動検出ロジックを提供する。
 * 検出結果は ['detected' => bool, 'signals' => [...], 'confidence' => 'high|medium|low|none']
 *
 * 信頼度の判定基準:
 *   high   - 専用プラグインが有効など、強いシグナル
 *   medium - 関連プラグインの存在など、推測可能なシグナル
 *   low    - 弱いヒューリスティック (デフォルト設定の存在等)
 *   none   - 検出不可 (要手動設定)
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Feature_Detector {

    /**
     * 全機能を検出する
     *
     * @return array [feature_id => ['detected' => bool, 'signals' => [], 'confidence' => string]]
     */
    public function detect_all() {
        $results = array();
        foreach (WPSG_Feature_Registry::get_all_feature_ids() as $feature_id) {
            $results[$feature_id] = $this->detect($feature_id);
        }
        return $results;
    }

    /**
     * 特定の機能を検出する
     *
     * @param string $feature_id
     * @return array
     */
    public function detect($feature_id) {
        $method = 'detect_' . $feature_id;
        if (method_exists($this, $method)) {
            $result = $this->{$method}();
        } else {
            $result = $this->no_signal();
        }

        // 拡張用のフィルター(将来サイトごとのカスタム検出を追加可能にする)
        $result = apply_filters('wpsg_inspect_detect_feature', $result, $feature_id);
        $result = apply_filters('wpsg_inspect_detect_feature_' . $feature_id, $result, $feature_id);

        return $this->normalize_result($result);
    }

    // ========================================================================
    // ヘルパー
    // ========================================================================

    /**
     * 検出シグナルなしの結果を返す
     */
    protected function no_signal() {
        return array(
            'detected'   => false,
            'signals'    => array(),
            'confidence' => 'none',
        );
    }

    /**
     * シグナルから検出結果を組み立てる
     *
     * @param array  $signals    検出された手がかり
     * @param string $confidence 信頼度
     * @return array
     */
    protected function from_signals($signals, $confidence = 'high') {
        $signals = array_values(array_unique(array_filter($signals)));
        if (empty($signals)) {
            return $this->no_signal();
        }
        return array(
            'detected'   => true,
            'signals'    => $signals,
            'confidence' => $confidence,
        );
    }

    /**
     * 結果を正規化する(キーの欠落を防ぐ)
     */
    protected function normalize_result($result) {
        if (!is_array($result)) {
            return $this->no_signal();
        }
        return array(
            'detected'   => isset($result['detected']) ? (bool) $result['detected'] : false,
            'signals'    => isset($result['signals']) && is_array($result['signals']) ? array_values($result['signals']) : array(),
            'confidence' => isset($result['confidence']) ? (string) $result['confidence'] : 'none',
        );
    }

    /**
     * プラグインが有効か(プラグインファイル名で判定)
     *
     * @param string $plugin_file 例: 'ultimate-member/index.php'
     * @return bool
     */
    protected function is_plugin_active($plugin_file) {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active($plugin_file);
    }

    /**
     * プラグインが「いずれか1つでも」有効か
     *
     * @param array $plugin_files
     * @return string|null 有効だったプラグインファイル名、または null
     */
    protected function any_plugin_active(array $plugin_files) {
        foreach ($plugin_files as $file) {
            if ($this->is_plugin_active($file)) {
                return $file;
            }
        }
        return null;
    }

    /**
     * テーブル存在確認
     *
     * @param string $table_name 完全修飾テーブル名
     * @return bool
     */
    protected function table_exists($table_name) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
    }

    // ========================================================================
    // 個別検出器: 共通系
    // ========================================================================

    protected function detect_membership() {
        $signals = array();
        if (class_exists('UM') || $this->is_plugin_active('ultimate-member/index.php')) {
            $signals[] = 'ultimate-member';
        }
        if (defined('BKP_PLUGIN_VER') || $this->is_plugin_active('bankpay/bankpay.php')) {
            $signals[] = 'bankpay';
        }
        if ($this->is_plugin_active('wp-full-stripe-free/wp-full-stripe-free.php')
            || $this->is_plugin_active('wp-full-stripe/wp-full-stripe.php')) {
            $signals[] = 'wp-full-stripe';
        }
        if ($this->is_plugin_active('wp-full-stripe-members/wp-full-stripe-members.php')) {
            $signals[] = 'wp-full-stripe-members';
        }
        if (class_exists('WooCommerce')) {
            $signals[] = 'woocommerce-customer';
        }
        return $this->from_signals($signals, 'high');
    }

    protected function detect_login() {
        // WordPressである時点でログイン機能は存在
        return $this->from_signals(array('wordpress-core'), 'high');
    }

    protected function detect_mypage() {
        $signals = array();
        if (class_exists('UM')) {
            $signals[] = 'ultimate-member-account';
        }
        if (class_exists('WooCommerce')) {
            // WC My Account ページが設定されているか
            $myaccount_page_id = (int) get_option('woocommerce_myaccount_page_id', 0);
            if ($myaccount_page_id > 0 && get_post($myaccount_page_id)) {
                $signals[] = 'wc-myaccount-page';
            }
        }
        if ($this->is_plugin_active('buddypress/bp-loader.php')) {
            $signals[] = 'buddypress';
        }
        return $this->from_signals($signals, count($signals) > 0 ? 'high' : 'none');
    }

    protected function detect_profile() {
        $signals = array();
        if (class_exists('UM')) {
            $signals[] = 'ultimate-member-profile';
        }
        if ($this->is_plugin_active('buddypress/bp-loader.php')) {
            $signals[] = 'buddypress-profile';
        }
        if (class_exists('WooCommerce')) {
            $signals[] = 'wc-customer-edit';
        }
        return $this->from_signals($signals, count($signals) > 0 ? 'high' : 'none');
    }

    // ========================================================================
    // 個別検出器: コミュニケーション系
    // ========================================================================

    protected function detect_forum() {
        $signals = array();
        if (class_exists('bbPress') || $this->is_plugin_active('bbpress/bbpress.php')) {
            $signals[] = 'bbpress';
        }
        if (defined('BP_PLUGIN_DIR') && function_exists('bp_is_active') && function_exists('bp_is_active') && bp_is_active('forums')) {
            $signals[] = 'buddypress-forums';
        }
        if ($this->is_plugin_active('asgaros-forum/asgaros-forum.php')) {
            $signals[] = 'asgaros-forum';
        }
        return $this->from_signals($signals, 'high');
    }

    protected function detect_chat() {
        $signals = array();
        // Better Messages
        if ($this->is_plugin_active('bp-better-messages/bp-better-messages.php')) {
            $signals[] = 'better-messages';
        }
        // 実装が独自の場合は検出困難
        return $this->from_signals($signals, count($signals) > 0 ? 'medium' : 'none');
    }

    protected function detect_dm() {
        $signals = array();
        if ($this->is_plugin_active('buddypress/bp-loader.php')
            && function_exists('bp_is_active') && bp_is_active('messages')) {
            $signals[] = 'buddypress-messages';
        }
        if ($this->is_plugin_active('um-friends/um-friends.php')) {
            $signals[] = 'um-friends';
        }
        return $this->from_signals($signals, count($signals) > 0 ? 'high' : 'none');
    }

    protected function detect_comment() {
        // WP標準のコメント機能はサイト全体で有効/無効
        $default_comment_status = get_option('default_comment_status', 'open');
        if ($default_comment_status === 'open') {
            return $this->from_signals(array('wp-default-comment-status-open'), 'high');
        }
        return $this->no_signal();
    }

    protected function detect_notification() {
        $signals = array();
        if ($this->is_plugin_active('buddypress/bp-loader.php')
            && function_exists('bp_is_active') && bp_is_active('notifications')) {
            $signals[] = 'buddypress-notifications';
        }
        // UMの通知機能 (アドオンの場合あり)
        if ($this->is_plugin_active('um-notifications/um-notifications.php')) {
            $signals[] = 'um-notifications';
        }
        return $this->from_signals($signals, count($signals) > 0 ? 'medium' : 'none');
    }

    // ========================================================================
    // 個別検出器: マッチング系
    // ========================================================================

    protected function detect_matching() {
        // マッチングは独自実装が多いため検出困難。手動設定推奨
        return $this->no_signal();
    }

    protected function detect_profile_search() {
        $signals = array();
        // UM Member Directory
        if (class_exists('UM') && $this->is_plugin_active('um-member-directory/um-member-directory.php')) {
            $signals[] = 'um-member-directory';
        }
        // UM の標準member directory機能
        if (class_exists('UM') && shortcode_exists('ultimatemember')) {
            $signals[] = 'um-shortcode-available';
        }
        return $this->from_signals($signals, count($signals) > 0 ? 'medium' : 'none');
    }

    protected function detect_favorites() {
        // 独自実装が多いため検出困難
        return $this->no_signal();
    }

    // ========================================================================
    // 個別検出器: EC・決済系
    // ========================================================================

    protected function detect_shop() {
        if (class_exists('WooCommerce')) {
            return $this->from_signals(array('woocommerce'), 'high');
        }
        return $this->no_signal();
    }

    protected function detect_cart() {
        if (class_exists('WooCommerce')) {
            return $this->from_signals(array('woocommerce-cart'), 'high');
        }
        return $this->no_signal();
    }

    protected function detect_subscription() {
        $signals = array();
        global $wpdb;

        // WP Full Stripe (Themeisle) - サブスクリプションフォームテーブルに行があれば確実
        $sub_table = $wpdb->prefix . 'fullstripe_subscription_forms';
        if ($this->table_exists($sub_table)) {
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sub_table}");
            if ($count > 0) {
                $signals[] = 'wp-full-stripe-subscriptions';
            } elseif ($this->is_plugin_active('wp-full-stripe-free/wp-full-stripe-free.php')) {
                $signals[] = 'wp-full-stripe-installed';
            }
        }

        // WC Subscriptions
        if (class_exists('WC_Subscriptions')) {
            $signals[] = 'woocommerce-subscriptions';
        }

        // BankPay (月額/年額)
        if (defined('BKP_PLUGIN_VER')) {
            $monthly = (int) get_option('bkp_op_monthly_fee', 0);
            $yearly  = (int) get_option('bkp_op_yearly_fee', 0);
            if ($monthly > 0 || $yearly > 0) {
                $signals[] = 'bankpay-recurring';
            }
        }

        return $this->from_signals($signals, 'high');
    }

    protected function detect_stripe() {
        $signals = array();
        if ($this->is_plugin_active('woocommerce-gateway-stripe/woocommerce-gateway-stripe.php')) {
            $signals[] = 'wc-stripe-gateway';
        }
        if ($this->is_plugin_active('wp-full-stripe-free/wp-full-stripe-free.php')
            || $this->is_plugin_active('wp-full-stripe/wp-full-stripe.php')) {
            $signals[] = 'wp-full-stripe';
        }
        if ($this->is_plugin_active('wc-vendors-stripe-connect/wc-vendors-stripe-connect.php')) {
            $signals[] = 'wc-vendors-stripe-connect';
        }
        return $this->from_signals($signals, 'high');
    }

    protected function detect_paypal() {
        $signals = array();
        // Payment Plugins for PayPal WooCommerce
        if ($this->is_plugin_active('pymntpls-paypal-woocommerce/pymntpls-paypal-woocommerce.php')) {
            $signals[] = 'paypal-payment-plugins';
        }
        // WooCommerce 標準の PayPal Payments
        if ($this->is_plugin_active('woocommerce-paypal-payments/woocommerce-paypal-payments.php')) {
            $signals[] = 'woocommerce-paypal-payments';
        }
        return $this->from_signals($signals, 'high');
    }

    protected function detect_bank_transfer() {
        $signals = array();
        if (defined('BKP_PLUGIN_VER') || $this->is_plugin_active('bankpay/bankpay.php')) {
            $signals[] = 'bankpay';
        }
        // WooCommerce の標準BACS(Direct bank transfer)
        if (class_exists('WooCommerce')) {
            $bacs_settings = get_option('woocommerce_bacs_settings', array());
            if (is_array($bacs_settings) && ($bacs_settings['enabled'] ?? 'no') === 'yes') {
                $signals[] = 'woocommerce-bacs';
            }
        }
        return $this->from_signals($signals, 'high');
    }

    protected function detect_multi_vendor() {
        $signals = array();
        if (class_exists('WC_Vendors') || $this->is_plugin_active('wc-vendors/class-wc-vendors.php')) {
            $signals[] = 'wc-vendors';
        }
        if ($this->is_plugin_active('wc-vendors-pro/wcvendors-pro.php')) {
            $signals[] = 'wc-vendors-pro';
        }
        // Dokan, MultiVendorX等
        if ($this->is_plugin_active('dokan-lite/dokan.php')) {
            $signals[] = 'dokan';
        }
        return $this->from_signals($signals, 'high');
    }

    // ========================================================================
    // 個別検出器: コンテンツ系
    // ========================================================================

    protected function detect_content_view() {
        // WPは投稿機能を持つので原則true
        return $this->from_signals(array('wordpress-core'), 'high');
    }

    protected function detect_paid_content() {
        $signals = array();
        if ($this->is_plugin_active('members/members.php')) {
            $signals[] = 'members-plugin';
        }
        if ($this->is_plugin_active('paid-memberships-pro/paid-memberships-pro.php')) {
            $signals[] = 'paid-memberships-pro';
        }
        if ($this->is_plugin_active('memberpress/memberpress.php')) {
            $signals[] = 'memberpress';
        }
        if ($this->is_plugin_active('restrict-content/restrict-content.php')) {
            $signals[] = 'restrict-content';
        }
        return $this->from_signals($signals, count($signals) > 0 ? 'high' : 'none');
    }

    protected function detect_live_streaming() {
        // 検出困難 (独自実装/外部サービス連携が多い)
        return $this->no_signal();
    }

    protected function detect_live_commerce() {
        // 検出困難
        return $this->no_signal();
    }

    // ========================================================================
    // 個別検出器: CF系
    // ========================================================================

    protected function detect_crowdfunding() {
        $signals = array();
        if (class_exists('WP_Crowdfunding') || $this->is_plugin_active('wp-crowdfunding/wp-crowdfunding.php')) {
            $signals[] = 'wp-crowdfunding';
        }
        if ($this->is_plugin_active('ignition-deck/ignition-deck.php')) {
            $signals[] = 'ignition-deck';
        }
        return $this->from_signals($signals, 'high');
    }

    protected function detect_project() {
        $signals = array();
        if (class_exists('WP_Crowdfunding')) {
            $signals[] = 'wp-crowdfunding-project';
        }
        return $this->from_signals($signals, count($signals) > 0 ? 'high' : 'none');
    }

    protected function detect_reward() {
        $signals = array();
        if (class_exists('WP_Crowdfunding')) {
            $signals[] = 'wp-crowdfunding-reward';
        }
        return $this->from_signals($signals, count($signals) > 0 ? 'high' : 'none');
    }

    // ========================================================================
    // 個別検出器: その他
    // ========================================================================

    protected function detect_review() {
        $signals = array();
        if (class_exists('WooCommerce')) {
            $reviews_enabled = get_option('woocommerce_enable_reviews', 'yes');
            if ($reviews_enabled === 'yes') {
                $signals[] = 'wc-reviews-enabled';
            }
        }
        return $this->from_signals($signals, count($signals) > 0 ? 'high' : 'none');
    }

    protected function detect_rating() {
        $signals = array();
        if (class_exists('WooCommerce')) {
            $reviews_enabled = get_option('woocommerce_enable_reviews', 'yes');
            $ratings_enabled = get_option('woocommerce_enable_review_rating', 'yes');
            if ($reviews_enabled === 'yes' && $ratings_enabled === 'yes') {
                $signals[] = 'wc-ratings-enabled';
            }
        }
        return $this->from_signals($signals, count($signals) > 0 ? 'high' : 'none');
    }
}
