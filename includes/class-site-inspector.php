<?php
/**
 * WP Security Guard - Site Inspector Module Loader
 *
 * 点検モジュールのエントリーポイント。
 * 既存のセキュリティ機能とは独立して動作する。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 点検モジュールのバージョン
 */
if (!defined('WPSG_INSPECTOR_VERSION')) {
    define('WPSG_INSPECTOR_VERSION', '1.4.0');
}

/**
 * 点検モジュールのRESTネームスペース
 */
if (!defined('WPSG_INSPECTOR_REST_NAMESPACE')) {
    define('WPSG_INSPECTOR_REST_NAMESPACE', 'wpsg/v1');
}

class WPSG_Site_Inspector {

    private static $initialized = false;

    /**
     * 点検モジュールの初期化
     * 既存の WPSecurityGuard クラスから独立して動作する
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        // 必須コンポーネントの読み込み
        require_once __DIR__ . '/class-inspect-token.php';
        require_once __DIR__ . '/class-inspect-rest-api.php';

        // Phase 2: 機能フラグ管理
        require_once __DIR__ . '/class-feature-registry.php';
        require_once __DIR__ . '/class-feature-detector.php';
        require_once __DIR__ . '/class-feature-storage.php';

        // Inspector基底クラスと個別inspector
        require_once __DIR__ . '/inspectors/class-inspector-base.php';
        require_once __DIR__ . '/inspectors/class-inspector-core.php';
        require_once __DIR__ . '/inspectors/class-inspector-theme.php';
        require_once __DIR__ . '/inspectors/class-inspector-plugins.php';
        require_once __DIR__ . '/inspectors/class-inspector-features.php';

        // Phase 3: 会員管理プラグイン別inspector
        require_once __DIR__ . '/inspectors/membership/class-membership-base.php';
        require_once __DIR__ . '/inspectors/membership/class-membership-ultimate-member.php';
        require_once __DIR__ . '/inspectors/membership/class-membership-wp-full-stripe.php';
        require_once __DIR__ . '/inspectors/membership/class-membership-woocommerce.php';
        require_once __DIR__ . '/inspectors/membership/class-membership-wc-vendors.php';
        require_once __DIR__ . '/inspectors/membership/class-membership-wp-crowdfunding.php';
        require_once __DIR__ . '/inspectors/membership/class-membership-bankpay.php';
        require_once __DIR__ . '/inspectors/membership/class-membership-dispatcher.php';
        require_once __DIR__ . '/inspectors/class-inspector-membership.php';

        // Phase 4: Stripe決済inspector
        require_once __DIR__ . '/inspectors/stripe/class-stripe-base.php';
        require_once __DIR__ . '/inspectors/stripe/class-stripe-wc-gateway.php';
        require_once __DIR__ . '/inspectors/stripe/class-stripe-wc-vendors-connect.php';
        require_once __DIR__ . '/inspectors/stripe/class-stripe-wp-full-stripe.php';
        require_once __DIR__ . '/inspectors/stripe/class-stripe-paypal-plugins.php';
        require_once __DIR__ . '/inspectors/stripe/class-stripe-bankpay.php';
        require_once __DIR__ . '/inspectors/stripe/class-stripe-dispatcher.php';
        require_once __DIR__ . '/inspectors/class-inspector-stripe.php';

        // SEO Guard（検索対策）★v2.9.0
        // 点検は読み取り専用。書き込みは Ed25519 署名を必須とする。
        require_once __DIR__ . '/class-seo-signature.php';
        require_once __DIR__ . '/class-seo-guard.php';
        require_once __DIR__ . '/inspectors/class-inspector-seo.php';
        WPSG_SEO_Guard::init();

        // 管理画面拡張（「6. サイト点検設定」「7. サイト機能フラグ管理」）は既定で非表示。★v2.5.2
        // 点検・プラグイン版取得は集中管理側（remakemanager）で行うため、各サイトのUIは不要。
        // 再表示が必要になったら: add_filter('wpsg_enable_inspector_admin_ui', '__return_true');
        if (is_admin() && apply_filters('wpsg_enable_inspector_admin_ui', false)) {
            require_once __DIR__ . '/admin/class-admin-page-extension.php';
            WPSG_Admin_Page_Extension::init();
        }

        // REST API登録（点検エンドポイントは維持＝remakemanager が利用）
        WPSG_Inspect_Rest_Api::init();

        // プラグイン一覧にシステム略語ラベルを表示（点検UIとは独立）。★v2.5.3
        if (is_admin()) {
            require_once __DIR__ . '/admin/class-plugin-labeler.php';
            WPSG_Plugin_Labeler::init();
        }
    }

    /**
     * モジュールのバージョンを返す
     *
     * @return string
     */
    public static function get_version() {
        return WPSG_INSPECTOR_VERSION;
    }
}
