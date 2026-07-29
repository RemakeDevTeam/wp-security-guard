<?php
/**
 * WP Security Guard - Admin Page Extension
 *
 * 既存のセキュリティガード設定画面の最下部に「6. サイト点検設定」セクションを追加する。
 * 既存のWPSecurityGuardクラスには一切手を加えない。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Admin_Page_Extension {

    /**
     * 平文トークンを一時表示するための user meta キー
     *
     * v2.4.1までは set_transient/get_transient を使っていたが、
     * オブジェクトキャッシュ (Redis/Memcached) 環境ではリダイレクト後に
     * get_transient() が false を返す問題があった。
     * v2.4.2 から user meta に変更。User Meta はDB直接書込なので
     * キャッシュ整合性問題の影響を受けにくい。
     */
    const FLASH_USER_META_KEY = '_wpsg_inspect_token_flash';

    /**
     * 平文トークンの保持タイムスタンプ (古いものを破棄するため)
     * 値が古すぎる場合は表示せず削除のみ行う
     */
    const FLASH_USER_META_EXPIRES_KEY = '_wpsg_inspect_token_flash_expires';

    /** 平文トークン保持秒数 (この時間を超えたら表示せず破棄) */
    const FLASH_TTL_SECONDS = 300;

    /**
     * @deprecated v2.4.2 - 旧 transient ベースの定義 (互換のため残置)
     */
    const FLASH_TRANSIENT_PREFIX = 'wpsg_inspect_token_flash_';
    const FLASH_TRANSIENT_TTL = 60;

    public static function init() {
        // 既存設定画面の末尾にセクションを描画する
        //
        // v2.4.0までは admin_footer-{hookname} で出力していたが、
        // この方法だと <div class="wrap"> が #wpcontent の外側 (#wpwrap直下) に
        // 配置されてしまい、左サイドバー(160px)の後ろに潜り込むレイアウト崩れが発生していた。
        //
        // v2.4.1からはメインプラグインの render_admin_page() の <div class="wrap">...</div>
        // 内部で発火する 'wpsg_admin_page_extension_render' フックで描画する。
        // これにより #wpcontent の中に正しく配置される。
        //
        // 古いメインプラグイン (v2.4.0以前のwp-security-guard.php) との後方互換のため、
        // 'wpsg_admin_page_extension_render' が発火しなかった場合は
        // 'in_admin_footer' フックでフォールバック描画する。

        add_action('admin_init', array(__CLASS__, 'handle_token_actions'));
        add_action('admin_init', array(__CLASS__, 'handle_allowed_ips_save'));
        add_action('admin_init', array(__CLASS__, 'handle_features_save'));
        add_action('admin_init', array(__CLASS__, 'handle_features_autodetect'));

        // v2.4.1の正規パス: メインプラグインのフック内で描画
        add_action('wpsg_admin_page_extension_render', array(__CLASS__, 'render_inspector_section'));
        add_action('wpsg_admin_page_extension_render', array(__CLASS__, 'render_features_section'));

        // 後方互換フォールバック: 古いメインプラグインの場合のみ動作
        add_action('admin_footer-toplevel_page_wp-security-guard', array(__CLASS__, 'render_legacy_fallback'), 5);
    }

    /**
     * 後方互換フォールバック描画
     * メインプラグインの do_action('wpsg_admin_page_extension_render') が呼ばれなかった場合のみ、
     * admin_footer で描画する (古いメインプラグインとの互換性維持)
     *
     * 描画後は static フラグでガードして二重描画を防ぐ
     */
    public static $rendered_inspector = false;
    public static $rendered_features = false;

    public static function render_legacy_fallback() {
        // すでに wpsg_admin_page_extension_render で描画済みなら何もしない
        if (self::$rendered_inspector && self::$rendered_features) {
            return;
        }
        // メインプラグインがフックを呼ばなかった場合のみここに来る
        // 警告も併せて表示する
        echo '<div class="wpsg-status-box wpsg-status-warning" style="padding: 12px 15px; margin: 20px; background: #fff8e5; border-left: 4px solid #dba617; border-radius: 2px;">';
        echo '<p><strong>WP Security Guard:</strong> ';
        echo 'メインプラグインを v2.4.1 以降に更新するとレイアウトが正しく表示されます。';
        echo '</p></div>';

        // 互換性のため、wrapセクションの中に出力するJSを使う
        if (!self::$rendered_inspector) {
            ob_start();
            self::render_inspector_section();
            $inspector_html = ob_get_clean();
        } else {
            $inspector_html = '';
        }
        if (!self::$rendered_features) {
            ob_start();
            self::render_features_section();
            $features_html = ob_get_clean();
        } else {
            $features_html = '';
        }

        // JSで #wpcontent 内の最初の .wrap の内側末尾に移動
        if ($inspector_html || $features_html) {
            ?>
            <div id="wpsg-fallback-host" style="display:none;"><?php
                echo $inspector_html . $features_html;
            ?></div>
            <script>
            (function() {
                var host = document.getElementById('wpsg-fallback-host');
                var wpcontent = document.getElementById('wpcontent');
                if (!host || !wpcontent) return;
                var firstWrap = wpcontent.querySelector('.wrap');
                if (!firstWrap) return;
                while (host.firstChild) {
                    firstWrap.appendChild(host.firstChild);
                }
                host.remove();
            })();
            </script>
            <?php
        }
    }

    // ========================================================================
    // フォーム処理
    // ========================================================================

    /**
     * トークン操作 (生成・再生成・削除) を処理
     */
    public static function handle_token_actions() {
        if (!is_admin()) {
            return;
        }
        if (!isset($_POST['wpsg_inspect_action'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        // Nonce検証
        $nonce = isset($_POST['wpsg_inspect_nonce']) ? sanitize_text_field(wp_unslash($_POST['wpsg_inspect_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wpsg_inspect_token_action')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

        $action = sanitize_text_field(wp_unslash($_POST['wpsg_inspect_action']));
        $user_id = get_current_user_id();

        if ($action === 'generate' || $action === 'regenerate') {
            $token = WPSG_Inspect_Token::generate();
            // 平文トークンをユーザーメタに一時保存
            // (v2.4.2: transient はオブジェクトキャッシュ環境で消失する可能性があるため変更)
            update_user_meta($user_id, self::FLASH_USER_META_KEY, $token);
            update_user_meta($user_id, self::FLASH_USER_META_EXPIRES_KEY, time() + self::FLASH_TTL_SECONDS);
            self::redirect_with_message($action === 'regenerate' ? 'token_regenerated' : 'token_generated');
        } elseif ($action === 'revoke') {
            WPSG_Inspect_Token::revoke();
            // 念のためフラッシュも削除
            delete_user_meta($user_id, self::FLASH_USER_META_KEY);
            delete_user_meta($user_id, self::FLASH_USER_META_EXPIRES_KEY);
            self::redirect_with_message('token_revoked');
        }
    }

    /**
     * 許可IPリストの保存処理
     */
    public static function handle_allowed_ips_save() {
        if (!is_admin()) {
            return;
        }
        if (!isset($_POST['wpsg_inspect_save_ips'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $nonce = isset($_POST['wpsg_inspect_ips_nonce']) ? sanitize_text_field(wp_unslash($_POST['wpsg_inspect_ips_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wpsg_inspect_ips_save')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

        $ips = isset($_POST['wpsg_inspect_allowed_ips']) ? sanitize_text_field(wp_unslash($_POST['wpsg_inspect_allowed_ips'])) : '';
        WPSG_Inspect_Token::set_allowed_ips($ips);
        self::redirect_with_message('ips_saved');
    }

    /**
     * 機能フラグ保存処理
     */
    public static function handle_features_save() {
        if (!is_admin()) {
            return;
        }
        if (!isset($_POST['wpsg_features_save'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $nonce = isset($_POST['wpsg_features_nonce']) ? sanitize_text_field(wp_unslash($_POST['wpsg_features_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wpsg_features_save')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

        // 入力値を集める
        $update = array();

        // システム種別
        if (isset($_POST['wpsg_system_type'])) {
            $update['system_type'] = sanitize_text_field(wp_unslash($_POST['wpsg_system_type']));
        }

        // サイトラベル
        if (isset($_POST['wpsg_site_label'])) {
            $update['site_label'] = sanitize_text_field(wp_unslash($_POST['wpsg_site_label']));
        }

        // 機能フラグ
        $features_input = isset($_POST['wpsg_features']) && is_array($_POST['wpsg_features'])
            ? wp_unslash($_POST['wpsg_features'])
            : array();
        $features = array();
        foreach (WPSG_Feature_Registry::get_all_feature_ids() as $feature_id) {
            $features[$feature_id] = !empty($features_input[$feature_id]);
        }
        $update['features'] = $features;

        // 備考
        if (isset($_POST['wpsg_features_notes'])) {
            $update['notes'] = sanitize_textarea_field(wp_unslash($_POST['wpsg_features_notes']));
        }

        WPSG_Feature_Storage::save($update);
        self::redirect_with_message('features_saved');
    }

    /**
     * 自動検出による現在値プリセット処理
     * 検出結果を「保存値」と「last_detected」の両方に書き込む
     */
    public static function handle_features_autodetect() {
        if (!is_admin()) {
            return;
        }
        if (!isset($_POST['wpsg_features_autodetect'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $nonce = isset($_POST['wpsg_features_nonce']) ? sanitize_text_field(wp_unslash($_POST['wpsg_features_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'wpsg_features_save')) {
            wp_die('セキュリティチェックに失敗しました。');
        }

        $detector = new WPSG_Feature_Detector();
        $detected_full = $detector->detect_all();

        // 検出されたものをONとする (none信頼度は据え置き → falseとなる)
        $features = array();
        $last_detected = array();
        foreach (WPSG_Feature_Registry::get_all_feature_ids() as $feature_id) {
            $is_detected = !empty($detected_full[$feature_id]['detected']);
            $features[$feature_id] = $is_detected;
            $last_detected[$feature_id] = $is_detected;
        }

        // システム種別: フォーム入力値があれば反映
        $update = array(
            'features'      => $features,
            'last_detected' => $last_detected,
        );
        if (isset($_POST['wpsg_system_type'])) {
            $update['system_type'] = sanitize_text_field(wp_unslash($_POST['wpsg_system_type']));
        }
        if (isset($_POST['wpsg_site_label'])) {
            $update['site_label'] = sanitize_text_field(wp_unslash($_POST['wpsg_site_label']));
        }
        if (isset($_POST['wpsg_features_notes'])) {
            $update['notes'] = sanitize_textarea_field(wp_unslash($_POST['wpsg_features_notes']));
        }

        WPSG_Feature_Storage::save($update);
        self::redirect_with_message('features_autodetected');
    }

    /**
     * リダイレクト用ヘルパー (POST→GET)
     *
     * @param string $message_code
     */
    private static function redirect_with_message($message_code) {
        $url = add_query_arg(array(
            'page'        => 'wp-security-guard',
            'wpsg_notice' => $message_code,
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    /**
     * フラッシュトークン (1度だけ表示する平文トークン) を user_meta から取り出して即削除する
     *
     * v2.4.2: transient ではなく user_meta を使う
     * 理由: オブジェクトキャッシュ環境 (Redis/Memcached) では set→redirect→get の流れで
     * transient が取れない問題があるため。User Meta は wp_usermeta テーブル直接書込なので
     * オブジェクトキャッシュの整合性問題の影響を受けにくい。
     *
     * @param int $user_id
     * @return string|false 平文トークン、もしくは無ければ/期限切れなら false
     */
    private static function consume_flash_token($user_id) {
        $token = get_user_meta($user_id, self::FLASH_USER_META_KEY, true);
        $expires = (int) get_user_meta($user_id, self::FLASH_USER_META_EXPIRES_KEY, true);

        // 値が空 or 期限切れ
        if (empty($token) || $expires <= 0 || $expires < time()) {
            // 念のため掃除
            delete_user_meta($user_id, self::FLASH_USER_META_KEY);
            delete_user_meta($user_id, self::FLASH_USER_META_EXPIRES_KEY);
            return false;
        }

        // 取り出したら即削除 (1度だけ表示する保証)
        delete_user_meta($user_id, self::FLASH_USER_META_KEY);
        delete_user_meta($user_id, self::FLASH_USER_META_EXPIRES_KEY);
        return (string) $token;
    }

    // ========================================================================
    // ビュー描画
    // ========================================================================

    /**
     * セキュリティガード設定画面の末尾に点検設定セクションを描画する
     */
    public static function render_inspector_section() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // 二重描画防止 (レガシーフォールバック用)
        self::$rendered_inspector = true;

        // 一時保存された平文トークンを取得 (1度だけ表示するため即削除)
        // v2.4.2: transient → user_meta に変更 (オブジェクトキャッシュ問題対策)
        $user_id = get_current_user_id();
        $plain_token = self::consume_flash_token($user_id);

        // v2.4.1以前との後方互換: transient から取れる場合はそちらも消費
        $legacy_flash_key = self::FLASH_TRANSIENT_PREFIX . $user_id;
        $legacy_token = get_transient($legacy_flash_key);
        if ($legacy_token !== false) {
            delete_transient($legacy_flash_key);
            // user_meta から取れなかった場合は legacy 値を使う
            if ($plain_token === false) {
                $plain_token = $legacy_token;
            }
        }

        // 通知メッセージ
        $notice = isset($_GET['wpsg_notice']) ? sanitize_text_field(wp_unslash($_GET['wpsg_notice'])) : '';

        // 値の取得
        $token_set = WPSG_Inspect_Token::is_set();
        $last_access = WPSG_Inspect_Token::get_last_access();
        $allowed_ips = WPSG_Inspect_Token::get_allowed_ips();
        $rest_url = trailingslashit(rest_url(WPSG_INSPECTOR_REST_NAMESPACE)) . 'inspect/';

        // ビュー読込
        include __DIR__ . '/views/inspector-settings.php';
    }

    /**
     * 機能フラグ編集セクションを描画する
     */
    public static function render_features_section() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // 二重描画防止 (レガシーフォールバック用)
        self::$rendered_features = true;

        $stored = WPSG_Feature_Storage::get();
        $current_system_type = isset($stored['system_type']) ? $stored['system_type'] : '';
        $current_site_label  = isset($stored['site_label']) ? $stored['site_label'] : '';
        $current_features    = WPSG_Feature_Storage::get_features();
        $current_notes       = isset($stored['notes']) ? $stored['notes'] : '';
        $last_synced_at      = isset($stored['last_synced_at']) ? $stored['last_synced_at'] : null;
        $last_updated_at     = isset($stored['last_updated_at']) ? $stored['last_updated_at'] : null;
        $is_configured       = WPSG_Feature_Storage::is_configured();

        // 自動検出を毎回走らせる(管理画面表示時のみ)
        $detector = new WPSG_Feature_Detector();
        $detected_results = $detector->detect_all();

        $notice = isset($_GET['wpsg_notice']) ? sanitize_text_field(wp_unslash($_GET['wpsg_notice'])) : '';

        // ビュー読込
        include __DIR__ . '/views/features-editor.php';
    }
}
