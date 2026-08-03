<?php
/*
Plugin Name: WP Security Guard
Plugin URI: https://github.com/RemakeDevTeam/wp-security-guard
Description: 統合セキュリティプラグイン。XML-RPC遮断・ユーザー名列挙対策・バージョン情報隠蔽・アプリケーションパスワード無効化・Contact Form 7 スパム対策・会員登録スパム対策・サイト点検モジュール(機能フラグ管理・会員管理・決済設定inspector)を1プラグインで管理します。自己ホスト更新(GitHub)対応。
Version: 2.6.0
Author:
License: GPL v2 or later
Text Domain: wp-security-guard
Update URI: https://github.com/RemakeDevTeam/wp-security-guard
*/

if (!defined('ABSPATH')) {
    exit;
}

class WPSecurityGuard {

    private static $instance = null;
    private $options;

    const OPTION_NAME = 'wp_security_guard_options';
    const DEFAULTS = array(
        // XML-RPC
        'block_xmlrpc'           => 'yes',

        // ユーザー名列挙対策
        'block_rest_users'       => 'yes',
        'block_author_query'     => 'yes',
        'unify_login_errors'     => 'yes',

        // 情報隠蔽
        'hide_wp_version'        => 'yes',

        // 認証強化
        'disable_app_passwords'  => 'yes',

        // Contact Form 7
        'enable_cf7_guard'       => 'yes',
        'message_field'          => 'your-message',
        'check_sender'           => 'no',
        'sender_field'           => 'your-name',
        'max_urls'               => 3,
        'min_japanese_chars'     => 5,

        // 会員登録スパム対策 ★v2.6.0
        'enable_registration_guard'  => 'yes',
        'block_wp_login_register'    => 'yes',
        'reg_require_js_token'       => 'yes',
        'reg_honeypot'               => 'yes',
        'reg_min_form_seconds'       => 3,
        'reg_max_per_ip_hour'        => 3,
        'reg_validate_username'      => 'yes',
        'reg_suppress_user_email'    => 'no',
        'reg_suppress_admin_email'   => 'no',
    );

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->options = wp_parse_args(
            get_option(self::OPTION_NAME, array()),
            self::DEFAULTS
        );

        // 各セキュリティ機能を適用(オプションに応じてON/OFF)
        $this->apply_xmlrpc_block();
        $this->apply_user_enumeration_protection();
        $this->apply_info_hiding();
        $this->apply_auth_hardening();
        $this->apply_cf7_guard();
        $this->apply_registration_guard(); // ★v2.6.0

        // 管理画面
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    // =====================================================================
    // 1. XML-RPC 遮断
    // =====================================================================
    private function apply_xmlrpc_block() {
        if ($this->options['block_xmlrpc'] !== 'yes') {
            return;
        }

        // xmlrpc.phpへの直接アクセスを403で遮断
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            status_header(403);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('Forbidden');
        }

        // XML-RPC機能そのものを無効化(保険)
        add_filter('xmlrpc_enabled', '__return_false');

        // X-Pingbackヘッダー削除
        add_filter('wp_headers', function ($headers) {
            unset($headers['X-Pingback']);
            return $headers;
        });

        // pingback関連メソッドも無効化
        add_filter('xmlrpc_methods', function ($methods) {
            unset($methods['pingback.ping']);
            unset($methods['pingback.extensions.getPingbacks']);
            return $methods;
        });
    }

    // =====================================================================
    // 2. ユーザー名列挙対策
    // =====================================================================
    private function apply_user_enumeration_protection() {

        // 2-1. REST API /wp/v2/users の未認証アクセスを遮断
        if ($this->options['block_rest_users'] === 'yes') {
            add_filter('rest_endpoints', function ($endpoints) {
                if (!is_user_logged_in()) {
                    if (isset($endpoints['/wp/v2/users'])) {
                        unset($endpoints['/wp/v2/users']);
                    }
                    if (isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
                        unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
                    }
                }
                return $endpoints;
            });
        }

        // 2-2. ?author=N クエリによるユーザー名露出を遮断
        if ($this->options['block_author_query'] === 'yes') {
            add_action('init', function () {
                if (!is_admin() && isset($_GET['author']) && !empty($_GET['author'])) {
                    wp_safe_redirect(home_url('/'), 301);
                    exit;
                }
            }, 1);

            // 内部リダイレクトでのauthorクエリも無効化
            add_filter('redirect_canonical', function ($redirect, $request) {
                if (preg_match('/\?author=([0-9]+)(\/*)/i', $request) ||
                    preg_match('/\/author\/(.+)/', $request)) {
                    return home_url('/');
                }
                return $redirect;
            }, 10, 2);
        }

        // 2-3. ログインエラーメッセージを統一
        if ($this->options['unify_login_errors'] === 'yes') {
            add_filter('login_errors', function () {
                return 'ユーザー名またはパスワードが正しくありません。';
            });

            // ユーザー名が存在するかどうかの判別を防ぐ
            add_filter('authenticate', function ($user, $username, $password) {
                if (is_wp_error($user)) {
                    $error_codes = $user->get_error_codes();
                    if (in_array('invalid_username', $error_codes) ||
                        in_array('invalid_email', $error_codes) ||
                        in_array('incorrect_password', $error_codes)) {
                        return new WP_Error(
                            'login_failed',
                            'ユーザー名またはパスワードが正しくありません。'
                        );
                    }
                }
                return $user;
            }, 999, 3);
        }
    }

    // =====================================================================
    // 3. WordPressバージョン情報の隠蔽
    // =====================================================================
    private function apply_info_hiding() {
        if ($this->options['hide_wp_version'] !== 'yes') {
            return;
        }

        // <meta name="generator">を削除
        remove_action('wp_head', 'wp_generator');

        // RSSフィードのgeneratorタグを空に
        add_filter('the_generator', '__return_empty_string');

        // CSS/JSの?ver=X.X.Xクエリからバージョン情報を削除
        $remove_ver = function ($src) {
            if (strpos($src, 'ver=')) {
                $src = remove_query_arg('ver', $src);
            }
            return $src;
        };
        add_filter('style_loader_src', $remove_ver, 9999);
        add_filter('script_loader_src', $remove_ver, 9999);
    }

    // =====================================================================
    // 4. アプリケーションパスワード無効化
    // =====================================================================
    private function apply_auth_hardening() {
        if ($this->options['disable_app_passwords'] === 'yes') {
            add_filter('wp_is_application_passwords_available', '__return_false');
        }
    }

    // =====================================================================
    // 5. Contact Form 7 スパム対策
    // =====================================================================
    private function apply_cf7_guard() {
        if ($this->options['enable_cf7_guard'] !== 'yes') {
            return;
        }

        add_filter('wpcf7_validate_textarea',  array($this, 'validate_cf7_text'), 10, 2);
        add_filter('wpcf7_validate_textarea*', array($this, 'validate_cf7_text'), 10, 2);
        add_filter('wpcf7_validate_text',      array($this, 'validate_cf7_text'), 10, 2);
        add_filter('wpcf7_validate_text*',     array($this, 'validate_cf7_text'), 10, 2);
    }

    public function validate_cf7_text($result, $tag) {
        $tag_name = $tag->name;
        $text = isset($_POST[$tag_name]) ? wp_unslash($_POST[$tag_name]) : '';

        // お問合せ本文フィールドのチェック
        if ($tag_name == $this->options['message_field']) {
            // 日本語必須チェック
            if (!empty($text) && !preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text)) {
                $result->invalidate($tag, '英語でのお問い合わせには対応しておりません');
                return $result;
            }

            // 最小日本語文字数チェック
            $min_ja = intval($this->options['min_japanese_chars']);
            if ($min_ja > 0 && !empty($text)) {
                preg_match_all('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text, $matches);
                $ja_count = isset($matches[0]) ? count($matches[0]) : 0;
                if ($ja_count < $min_ja) {
                    $result->invalidate($tag, 'お問合せ内容が短すぎます。もう少し詳しくご記入ください。');
                    return $result;
                }
            }

            // URL数上限チェック
            $max_urls = intval($this->options['max_urls']);
            if ($max_urls > 0 && !empty($text)) {
                $url_count = preg_match_all('/https?:\/\/[^\s<>"\']+|www\.[^\s<>"\']+/i', $text);
                if ($url_count > $max_urls) {
                    $result->invalidate($tag, 'お問合せ内容に含まれるURLが多すぎます');
                    return $result;
                }
            }
        }

        // 差出人名フィールドのチェック
        if ($this->options['check_sender'] === 'yes' && $tag_name == $this->options['sender_field']) {
            if (!empty($text) && !preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text)) {
                $result->invalidate($tag, '英語でのお問い合わせには対応しておりません');
            }
        }

        return $result;
    }

    // =====================================================================
    // 6. 会員登録スパム対策 ★v2.6.0
    //    wp-login.php?action=register と WooCommerce /my-account/ の両方に適用。
    //    JS実行なしのスクリプト型POSTボットを主対象に、多層で遮断する。
    // =====================================================================
    private function apply_registration_guard() {
        if ($this->options['enable_registration_guard'] !== 'yes') {
            return;
        }

        // wp-login.php?action=register の遮断（users_can_register の値に依存しない）
        if ($this->options['block_wp_login_register'] === 'yes') {
            add_action('login_init', array($this, 'block_wp_login_registration'));
        }

        // フォームへの防御フィールド出力（WP標準＋WooCommerce）
        add_action('register_form', array($this, 'render_guard_fields'));
        add_action('woocommerce_register_form', array($this, 'render_guard_fields'));

        // 検証（両フックとも引数並びは $errors, $sanitized_user_login, $user_email で共通）
        add_filter('registration_errors', array($this, 'validate_registration'), 10, 3);
        add_filter('woocommerce_registration_errors', array($this, 'validate_registration'), 10, 3);

        // 登録成功後のレート制限カウンタ更新
        add_action('user_register', array($this, 'record_registration_ip'));

        // メール抑制
        if ($this->options['reg_suppress_user_email'] === 'yes') {
            add_filter('wp_new_user_notification_email', array($this, 'suppress_user_notification'), 10, 3);
        }
        if ($this->options['reg_suppress_admin_email'] === 'yes') {
            add_filter('wp_new_user_notification_email_admin', array($this, 'suppress_admin_notification'), 10, 3);
        }
    }

    /**
     * wp-login.php?action=register を GET/POST 双方で遮断してトップへ。
     * 登録機能の存在を攻撃者に悟らせないため registration=disabled 等は付けない。
     */
    public function block_wp_login_registration() {
        $action = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : '';
        if ($action === 'register') {
            wp_safe_redirect(home_url('/'), 302);
            exit;
        }
    }

    /**
     * 3つの防御フィールド（ハニーポット・署名付き時間トラップ・JSトークン）を出力。
     */
    public function render_guard_fields() {
        $ts = time();
        $hp_name = $this->get_honeypot_field_name();

        // 1. ハニーポット（display:none だと検知されやすいので画面外に飛ばして隠す）
        if ($this->options['reg_honeypot'] === 'yes') {
            echo '<div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">';
            echo '<label>' . esc_html__('この欄は入力しないでください', 'wp-security-guard') . '</label>';
            echo '<input type="text" name="' . esc_attr($hp_name) . '" value="" tabindex="-1" autocomplete="off">';
            echo '</div>';
        }

        // 2. 時間トラップ（改ざん防止に署名付き）
        if (intval($this->options['reg_min_form_seconds']) > 0) {
            echo '<input type="hidden" name="wpsg_reg_ts" value="' . esc_attr($ts) . '">';
            echo '<input type="hidden" name="wpsg_reg_sig" value="' . esc_attr($this->sign_timestamp($ts)) . '">';
        }

        // 3. JSトークン（JS実行なしのボットを排除。最も効果が高い）
        if ($this->options['reg_require_js_token'] === 'yes') {
            echo '<input type="hidden" name="wpsg_reg_token" value="">';
            echo '<script>(function(){var f=document.querySelectorAll(\'input[name="wpsg_reg_token"]\');'
               . 'for(var i=0;i<f.length;i++){f[i].value=' . wp_json_encode($this->get_js_token()) . ';}})();</script>';
        }
    }

    /**
     * ハニーポットのフィールド名。固定名は学習されるため wp_salt でサイト固有かつ安定にする。
     */
    private function get_honeypot_field_name() {
        return 'wpsg_' . substr(md5('wpsg_hp' . wp_salt('auth')), 0, 12);
    }

    /**
     * 時間トラップ用タイムスタンプの HMAC 署名。
     */
    private function sign_timestamp($ts) {
        return hash_hmac('sha256', 'wpsg_reg|' . $ts, wp_salt('auth'));
    }

    /**
     * JSトークン（当日分）。1日単位でローテーション（キャッシュ耐性と有効期限のバランス）。
     */
    private function get_js_token() {
        return $this->js_token_for_date(gmdate('Y-m-d'));
    }

    private function js_token_for_date($date) {
        return substr(hash_hmac('sha256', 'wpsg_js|' . $date, wp_salt('auth')), 0, 32);
    }

    /**
     * JSトークン検証。UTC日付境界をまたぐ送信に備え、当日分・前日分の両方を許可する。
     */
    private function verify_js_token($token) {
        if ($token === '') {
            return false;
        }
        $today     = $this->js_token_for_date(gmdate('Y-m-d'));
        $yesterday = $this->js_token_for_date(gmdate('Y-m-d', time() - DAY_IN_SECONDS));
        return hash_equals($today, $token) || hash_equals($yesterday, $token);
    }

    /**
     * 会員登録の検証。WP標準・WooCommerce の registration_errors 共通。
     */
    public function validate_registration($errors, $sanitized_user_login, $user_email) {
        // 管理画面からの手動作成は対象外
        if (is_admin() && current_user_can('create_users')) {
            return $errors;
        }

        // 将来の外部CAPTCHAモジュール差し込み口
        $pre = apply_filters('wpsg_registration_pre_validate', null, $errors, $user_email);
        if (is_wp_error($pre)) {
            return $pre;
        }

        $generic = __('登録処理を完了できませんでした。時間をおいて再度お試しください。', 'wp-security-guard');

        // (1) ハニーポット
        if ($this->options['reg_honeypot'] === 'yes') {
            $hp = $this->get_honeypot_field_name();
            if (!empty($_POST[$hp])) {
                $this->log_block('honeypot', $user_email);
                $errors->add('wpsg_hp', $generic);
                return $errors;
            }
        }

        // (2) JSトークン
        if ($this->options['reg_require_js_token'] === 'yes') {
            $token = isset($_POST['wpsg_reg_token']) ? sanitize_text_field(wp_unslash($_POST['wpsg_reg_token'])) : '';
            if (!$this->verify_js_token($token)) {
                $this->log_block('js_token', $user_email);
                $errors->add('wpsg_js', __('お使いのブラウザでJavaScriptが無効になっています。有効にしてから再度お試しください。', 'wp-security-guard'));
                return $errors;
            }
        }

        // (3) 時間トラップ（下限のみ。キャッシュ起因の誤検知回避のため上限は見ない）
        $min_sec = intval($this->options['reg_min_form_seconds']);
        if ($min_sec > 0) {
            $ts  = isset($_POST['wpsg_reg_ts'])  ? intval($_POST['wpsg_reg_ts']) : 0;
            $sig = isset($_POST['wpsg_reg_sig']) ? sanitize_text_field(wp_unslash($_POST['wpsg_reg_sig'])) : '';
            if (!$ts || !hash_equals($this->sign_timestamp($ts), $sig) || (time() - $ts) < $min_sec) {
                $this->log_block('time_trap', $user_email);
                $errors->add('wpsg_time', $generic);
                return $errors;
            }
        }

        // (4) レート制限
        $max_ip = intval($this->options['reg_max_per_ip_hour']);
        if ($max_ip > 0 && $this->get_ip_registration_count() >= $max_ip) {
            $this->log_block('rate_limit', $user_email);
            $errors->add('wpsg_rate', __('短時間に多数の登録が行われました。しばらく時間をおいてからお試しください。', 'wp-security-guard'));
            return $errors;
        }

        // (5) ユーザー名・表示名バリデーション
        if ($this->options['reg_validate_username'] === 'yes') {
            $candidates = array($sanitized_user_login);
            foreach (array('username', 'display_name', 'first_name', 'last_name', 'billing_first_name', 'billing_last_name') as $f) {
                if (!empty($_POST[$f])) {
                    $candidates[] = sanitize_text_field(wp_unslash($_POST[$f]));
                }
            }
            foreach ($candidates as $c) {
                if ($this->looks_like_spam($c)) {
                    $this->log_block('username_pattern', $user_email);
                    $errors->add('wpsg_name', __('お名前・ユーザー名に使用できない文字列が含まれています。', 'wp-security-guard'));
                    return $errors;
                }
            }
        }

        return $errors;
    }

    /**
     * ユーザー名等がスパム広告文らしいか判定。キーワード・判定結果ともフィルタで上書き可能。
     */
    private function looks_like_spam($str) {
        $is_spam = $this->registration_spam_match($str);
        return (bool) apply_filters('wpsg_registration_looks_like_spam', $is_spam, $str);
    }

    private function registration_spam_match($str) {
        if ($str === '') {
            return false;
        }

        // URL・スキーム
        if (preg_match('#https?://|www\.#i', $str)) {
            return true;
        }

        // 使い捨て・不正が多いTLDのドメイン様文字列（例: cj503302.tw1.ru）
        if (preg_match('/\b[a-z0-9-]+\.(ru|su|tk|ml|ga|cf|gq|xyz|top|club|online|site|icu)\b/i', $str)) {
            return true;
        }

        // 3階層以上のドット区切り英数字（サブドメイン付きドメイン様）
        if (preg_match('/\b[a-z0-9-]+\.[a-z0-9-]+\.[a-z]{2,}\b/i', $str)) {
            return true;
        }

        // スパム定型句
        $keywords = array(
            'action required', 'bitcoin', 'btc', 'free spins', 'roulette',
            'casino', 'bonus', 'voucher', 'giveaway', 'crypto', 'nft',
            'wallet', 'transfer', 'payout', 'claim your', 'you have won',
            'viagra', 'cialis', 'loan', 'seo service',
        );
        $keywords = apply_filters('wpsg_registration_spam_keywords', $keywords);
        $lower = mb_strtolower($str, 'UTF-8');
        foreach ($keywords as $kw) {
            if ($kw !== '' && mb_strpos($lower, $kw) !== false) {
                return true;
            }
        }

        // 過度に長いユーザー名（実測: スパムは40〜60文字。正規顧客は最長20文字程度）
        if (mb_strlen($str) > 40) {
            return true;
        }

        return false;
    }

    // ---- レート制限（トランジェント。IPは平文保存しない） ----
    private function get_client_ip() {
        // 本番は nginx 直（CDN/プロキシなし）。Cloudflare等導入時は wpsg_client_ip で差し替え。
        // X-Forwarded-For は偽装され放題のため既定では信頼しない。
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        return apply_filters('wpsg_client_ip', $ip);
    }

    private function get_ip_transient_key() {
        return 'wpsg_reg_' . md5($this->get_client_ip() . wp_salt('auth'));
    }

    private function get_ip_registration_count() {
        return intval(get_transient($this->get_ip_transient_key()));
    }

    public function record_registration_ip($user_id) {
        if ($this->options['enable_registration_guard'] !== 'yes') {
            return;
        }
        $key   = $this->get_ip_transient_key();
        $count = intval(get_transient($key));
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
    }

    // ---- メール抑制 ----
    public function suppress_user_notification($email, $user, $blogname) {
        $email['to'] = ''; // 送信先を空にして送信を中止させる
        return $email;
    }

    public function suppress_admin_notification($email, $user, $blogname) {
        $email['to'] = '';
        return $email;
    }

    // ---- ブロックログ（WP_DEBUG時のみ。メール以外の個人情報は残さない） ----
    private function log_block($reason, $email) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        error_log(sprintf('[WPSG] registration blocked: reason=%s email=%s', $reason, $email));
    }

    // =====================================================================
    // 管理画面
    // =====================================================================
    public function add_admin_menu() {
        add_menu_page(
            'セキュリティガード',
            'セキュリティガード',
            'manage_options',
            'wp-security-guard',
            array($this, 'render_admin_page'),
            'dashicons-shield',
            80
        );
    }

    public function register_settings() {
        register_setting(
            'wp_security_guard_settings',
            self::OPTION_NAME,
            array($this, 'sanitize_options')
        );

        // ---- セクション1: XML-RPC ----
        add_settings_section(
            'sec_xmlrpc',
            '1. XML-RPC (xmlrpc.php) 遮断',
            function () {
                echo '<p>xmlrpc.phpへの不正アクセスを遮断します。WordPressモバイルアプリやJetpackを使っていない場合は有効を推奨。</p>';
            },
            'wp-security-guard'
        );
        add_settings_field('block_xmlrpc', 'XML-RPCを遮断する',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_xmlrpc',
            array('key' => 'block_xmlrpc', 'desc' => '有効にするとxmlrpc.phpが403で拒否されます')
        );

        // ---- セクション2: ユーザー名列挙対策 ----
        add_settings_section(
            'sec_user_enum',
            '2. ユーザー名列挙対策',
            function () {
                echo '<p>攻撃者によるユーザー名(ログインID)の取得を防ぎます。ブルートフォース攻撃の成功率を大幅に下げます。</p>';
            },
            'wp-security-guard'
        );
        add_settings_field('block_rest_users', 'REST APIのユーザー情報を保護',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_user_enum',
            array('key' => 'block_rest_users', 'desc' => '/wp-json/wp/v2/users への未認証アクセスを遮断')
        );
        add_settings_field('block_author_query', '?author=Nリクエストを遮断',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_user_enum',
            array('key' => 'block_author_query', 'desc' => 'example.com/?author=1 などでユーザー名が露出するのを防止')
        );
        add_settings_field('unify_login_errors', 'ログインエラーメッセージを統一',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_user_enum',
            array('key' => 'unify_login_errors', 'desc' => 'ユーザー名の存在有無を判別できないようにします')
        );

        // ---- セクション3: 情報隠蔽 ----
        add_settings_section(
            'sec_info_hide',
            '3. WordPressバージョン情報の隠蔽',
            function () {
                echo '<p>WordPressのバージョンを外部から判別できないようにし、既知の脆弱性を狙った攻撃を受けにくくします。</p>';
            },
            'wp-security-guard'
        );
        add_settings_field('hide_wp_version', 'バージョン情報を隠す',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_info_hide',
            array('key' => 'hide_wp_version', 'desc' => 'meta generator / RSS / CSS・JSクエリからバージョンを削除')
        );

        // ---- セクション4: 認証強化 ----
        add_settings_section(
            'sec_auth',
            '4. 認証強化',
            function () {
                echo '<p>WordPress 5.6以降の「アプリケーションパスワード」機能を使っていない場合は無効化を推奨。</p>';
            },
            'wp-security-guard'
        );
        add_settings_field('disable_app_passwords', 'アプリケーションパスワードを無効化',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_auth',
            array('key' => 'disable_app_passwords', 'desc' => 'REST API経由のブルートフォース経路を遮断。モバイルアプリ連携を使う場合は無効にしてください')
        );

        // ---- セクション5: Contact Form 7 ----
        add_settings_section(
            'sec_cf7',
            '5. Contact Form 7 スパム対策',
            function () {
                echo '<p>海外スパムによる大量のお問い合わせを自動的に弾きます。</p>';
            },
            'wp-security-guard'
        );
        add_settings_field('enable_cf7_guard', 'CF7スパム対策を有効化',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_cf7',
            array('key' => 'enable_cf7_guard', 'desc' => '')
        );
        add_settings_field('message_field', 'お問合せ本文のフィールドID',
            array($this, 'field_text'), 'wp-security-guard', 'sec_cf7',
            array('key' => 'message_field', 'id' => 'message_field')
        );
        add_settings_field('min_japanese_chars', '最小日本語文字数',
            array($this, 'field_number'), 'wp-security-guard', 'sec_cf7',
            array('key' => 'min_japanese_chars', 'desc' => '本文中の日本語文字がこの数未満の場合ブロック(0=制限なし)', 'min' => 0, 'max' => 1000)
        );
        add_settings_field('max_urls', 'URL数の上限',
            array($this, 'field_number'), 'wp-security-guard', 'sec_cf7',
            array('key' => 'max_urls', 'desc' => '本文中のURLがこの数を超える場合ブロック(0=制限なし)', 'min' => 0, 'max' => 100)
        );
        add_settings_field('check_sender', '差出人名もチェックする',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_cf7',
            array('key' => 'check_sender', 'desc' => '', 'labels' => array('はい', 'いいえ'))
        );
        add_settings_field('sender_field', '差出人名フィールドID',
            array($this, 'field_text'), 'wp-security-guard', 'sec_cf7',
            array('key' => 'sender_field', 'id' => 'sender_field')
        );

        // ---- セクション6: 会員登録スパム対策 ★v2.6.0 ----
        add_settings_section(
            'sec_registration',
            '6. 会員登録スパム対策',
            function () {
                echo '<p>ボットによるスパム会員登録を防ぎます。WordPress標準の登録フォーム（wp-login.php）と WooCommerce のマイアカウント登録フォームの両方に適用されます。</p>';
                echo '<p><strong>JSトークン検証</strong>が最も効果的です。JavaScriptを実行しない自動化ツールをほぼ完全に遮断します。</p>';
            },
            'wp-security-guard'
        );
        add_settings_field('enable_registration_guard', '会員登録スパム対策を有効化',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_registration',
            array('key' => 'enable_registration_guard', 'desc' => 'このモジュール全体のマスタースイッチ。無効にすると以下の防御はすべて動作しません')
        );
        add_settings_field('block_wp_login_register', 'wp-login.php の登録を遮断',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_registration',
            array('key' => 'block_wp_login_register', 'desc' => 'wp-login.php?action=register への直接POSTをトップへリダイレクト。WordPress設定「誰でも登録可」の値に依存せず遮断します')
        );
        add_settings_field('reg_require_js_token', 'JSトークン検証（推奨）',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_registration',
            array('key' => 'reg_require_js_token', 'desc' => '最も効果が高い防御。JavaScriptを実行しないボットを遮断します')
        );
        add_settings_field('reg_honeypot', 'ハニーポット',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_registration',
            array('key' => 'reg_honeypot', 'desc' => '画面外の隠し入力欄。ボットが埋めると拒否します')
        );
        add_settings_field('reg_min_form_seconds', 'フォーム最小滞在秒数',
            array($this, 'field_number'), 'wp-security-guard', 'sec_registration',
            array('key' => 'reg_min_form_seconds', 'desc' => 'フォーム表示からこの秒数未満での送信を拒否（0=無効）', 'min' => 0, 'max' => 60)
        );
        add_settings_field('reg_max_per_ip_hour', '1IPあたりの1時間登録上限',
            array($this, 'field_number'), 'wp-security-guard', 'sec_registration',
            array('key' => 'reg_max_per_ip_hour', 'desc' => '同一IPからの登録回数の上限（0=無効）', 'min' => 0, 'max' => 100)
        );
        add_settings_field('reg_validate_username', 'ユーザー名の内容検証',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_registration',
            array('key' => 'reg_validate_username', 'desc' => 'ユーザー名・お名前にURL/広告文/長すぎる文字列が含まれる登録を拒否（英語圏顧客が想定される場合はwpsg_registration_spam_keywordsフィルタで調整）')
        );
        add_settings_field('reg_suppress_user_email', '登録者宛メールを送らない',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_registration',
            array('key' => 'reg_suppress_user_email', 'desc' => '⚠️ 有効にすると正規の新規顧客もパスワード設定メールを受け取れません。副作用を理解した上で有効化してください（既定=無効）')
        );
        add_settings_field('reg_suppress_admin_email', '管理者宛の新規登録通知を送らない',
            array($this, 'field_radio_yes_no'), 'wp-security-guard', 'sec_registration',
            array('key' => 'reg_suppress_admin_email', 'desc' => '管理者宛の「新規ユーザー登録」通知メールのみ停止（副作用は小）')
        );
    }

    public function sanitize_options($input) {
        $clean = array();

        $yn_keys = array(
            'block_xmlrpc', 'block_rest_users', 'block_author_query',
            'unify_login_errors', 'hide_wp_version', 'disable_app_passwords',
            'enable_cf7_guard', 'check_sender',
            // 会員登録スパム対策 ★v2.6.0
            'enable_registration_guard', 'block_wp_login_register',
            'reg_require_js_token', 'reg_honeypot', 'reg_validate_username',
            'reg_suppress_user_email', 'reg_suppress_admin_email',
        );
        foreach ($yn_keys as $k) {
            $clean[$k] = (isset($input[$k]) && $input[$k] === 'yes') ? 'yes' : 'no';
        }

        $clean['message_field']      = isset($input['message_field']) ? sanitize_text_field($input['message_field']) : 'your-message';
        $clean['sender_field']       = isset($input['sender_field'])  ? sanitize_text_field($input['sender_field'])  : 'your-name';
        $clean['max_urls']           = isset($input['max_urls'])           ? max(0, intval($input['max_urls']))           : 3;
        $clean['min_japanese_chars'] = isset($input['min_japanese_chars']) ? max(0, intval($input['min_japanese_chars'])) : 5;

        // 会員登録スパム対策の数値キー（クランプ）★v2.6.0
        $clean['reg_min_form_seconds'] = isset($input['reg_min_form_seconds']) ? min(60,  max(0, intval($input['reg_min_form_seconds']))) : 3;
        $clean['reg_max_per_ip_hour']  = isset($input['reg_max_per_ip_hour'])  ? min(100, max(0, intval($input['reg_max_per_ip_hour'])))  : 3;

        return $clean;
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>セキュリティガード設定</h1>
            <p>このプラグインは複数のセキュリティ機能を統合管理します。各項目は個別にON/OFFできます。</p>
            <form method="post" action="options.php">
                <?php
                settings_fields('wp_security_guard_settings');
                do_settings_sections('wp-security-guard');
                submit_button();
                ?>
            </form>
            <?php
            /**
             * 設定画面の本体下部・閉じ </div> の直前で発火する拡張フック
             * 点検モジュール (Phase 1〜4) はこのフックでセクションを描画する
             *
             * @since 2.4.1
             */
            do_action('wpsg_admin_page_extension_render');
            ?>
        </div>
        <script>
        jQuery(document).ready(function($) {
            function toggleSenderField() {
                var checkSender = $('input[name="<?php echo esc_js(self::OPTION_NAME); ?>[check_sender]"]:checked').val();
                $('#sender_field').closest('tr').toggle(checkSender === 'yes');
            }
            function toggleCf7Fields() {
                var enabled = $('input[name="<?php echo esc_js(self::OPTION_NAME); ?>[enable_cf7_guard]"]:checked').val() === 'yes';
                var rows = $('input[name="<?php echo esc_js(self::OPTION_NAME); ?>[message_field]"]').closest('tr')
                    .add($('input[name="<?php echo esc_js(self::OPTION_NAME); ?>[check_sender]"]').closest('tr'))
                    .add($('#sender_field').closest('tr'))
                    .add($('input[name="<?php echo esc_js(self::OPTION_NAME); ?>[max_urls]"]').closest('tr'))
                    .add($('input[name="<?php echo esc_js(self::OPTION_NAME); ?>[min_japanese_chars]"]').closest('tr'));
                if (enabled) {
                    rows.show();
                    toggleSenderField();
                } else {
                    rows.hide();
                }
            }
            $('input[name="<?php echo esc_js(self::OPTION_NAME); ?>[check_sender]"]').change(toggleSenderField);
            $('input[name="<?php echo esc_js(self::OPTION_NAME); ?>[enable_cf7_guard]"]').change(toggleCf7Fields);
            toggleCf7Fields();

            // 会員登録スパム対策：マスタースイッチで残り8項目を有効/無効化 ★v2.6.0
            function toggleRegFields() {
                var enabled = $('input[name="<?php echo esc_js(self::OPTION_NAME); ?>[enable_registration_guard]"]:checked').val() === 'yes';
                var keys = ['block_wp_login_register','reg_require_js_token','reg_honeypot',
                    'reg_min_form_seconds','reg_max_per_ip_hour','reg_validate_username',
                    'reg_suppress_user_email','reg_suppress_admin_email'];
                var rows = $();
                for (var i = 0; i < keys.length; i++) {
                    rows = rows.add($('[name="<?php echo esc_js(self::OPTION_NAME); ?>[' + keys[i] + ']"]').closest('tr'));
                }
                rows.toggle(enabled);
            }
            $('input[name="<?php echo esc_js(self::OPTION_NAME); ?>[enable_registration_guard]"]').change(toggleRegFields);
            toggleRegFields();
        });
        </script>
        <?php
    }

    // =====================================================================
    // フィールド描画ヘルパー
    // =====================================================================
    public function field_radio_yes_no($args) {
        $key = $args['key'];
        $v = isset($this->options[$key]) ? $this->options[$key] : 'no';
        $labels = isset($args['labels']) ? $args['labels'] : array('有効', '無効');
        $name = self::OPTION_NAME . '[' . $key . ']';

        echo '<label><input type="radio" name="' . esc_attr($name) . '" value="yes"' . checked($v, 'yes', false) . '> ' . esc_html($labels[0]) . '</label>&nbsp;&nbsp;';
        echo '<label><input type="radio" name="' . esc_attr($name) . '" value="no"'  . checked($v, 'no',  false) . '> ' . esc_html($labels[1]) . '</label>';
        if (!empty($args['desc'])) {
            echo '<p class="description">' . esc_html($args['desc']) . '</p>';
        }
    }

    public function field_text($args) {
        $key = $args['key'];
        $v = isset($this->options[$key]) ? $this->options[$key] : '';
        $id = isset($args['id']) ? ' id="' . esc_attr($args['id']) . '"' : '';
        echo '<input type="text"' . $id . ' name="' . esc_attr(self::OPTION_NAME . '[' . $key . ']') . '" value="' . esc_attr($v) . '" class="regular-text">';
        if (!empty($args['desc'])) {
            echo '<p class="description">' . esc_html($args['desc']) . '</p>';
        }
    }

    public function field_number($args) {
        $key = $args['key'];
        $v = isset($this->options[$key]) ? $this->options[$key] : 0;
        $min = isset($args['min']) ? $args['min'] : 0;
        $max = isset($args['max']) ? $args['max'] : 9999;
        echo '<input type="number" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" name="' . esc_attr(self::OPTION_NAME . '[' . $key . ']') . '" value="' . esc_attr($v) . '" class="small-text">';
        if (!empty($args['desc'])) {
            echo '<p class="description">' . esc_html($args['desc']) . '</p>';
        }
    }
}

// プラグイン初期化
add_action('plugins_loaded', array('WPSecurityGuard', 'get_instance'));

// 点検モジュールの読み込み(Phase 1で追加)
require_once __DIR__ . '/includes/class-site-inspector.php';
add_action('plugins_loaded', array('WPSG_Site_Inspector', 'init'), 11);

/**
 * 自己ホスト更新チェッカー (plugin-update-checker / GitHub) ★v2.5.0
 * - 更新元リポジトリは既定で下記。wp-config で define('WPSG_UPDATE_REPO', '...') により上書き可。
 * - プライベートリポジトリは define('WPSG_UPDATE_TOKEN', 'ghp_xxx') でアクセストークン指定。
 * - 安定版ブランチを使う場合は define('WPSG_UPDATE_BRANCH', 'main')。既定はGitHubリリースを追跡。
 */
require_once __DIR__ . '/includes/lib/plugin-update-checker/plugin-update-checker.php';
if (class_exists('\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
    $wpsg_update_repo = defined('WPSG_UPDATE_REPO') ? WPSG_UPDATE_REPO : 'https://github.com/RemakeDevTeam/wp-security-guard/';
    $wpsg_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        $wpsg_update_repo,
        __FILE__,
        'wp-security-guard'
    );
    if (defined('WPSG_UPDATE_TOKEN') && WPSG_UPDATE_TOKEN) {
        $wpsg_update_checker->setAuthentication(WPSG_UPDATE_TOKEN);
    }
    if (defined('WPSG_UPDATE_BRANCH') && WPSG_UPDATE_BRANCH) {
        $wpsg_update_checker->setBranch(WPSG_UPDATE_BRANCH);
    }
}

/**
 * このプラグイン自身の自動更新を既定でONにする（全サイト手間ゼロ運用）。★v2.5.0
 * 無効化したい場合は wp-config で define('WPSG_DISABLE_AUTO_UPDATE', true)。
 */
add_filter('auto_update_plugin', function ($update, $item) {
    if (defined('WPSG_DISABLE_AUTO_UPDATE') && WPSG_DISABLE_AUTO_UPDATE) {
        return $update;
    }
    if (is_object($item) && isset($item->slug) && $item->slug === 'wp-security-guard') {
        return true;
    }
    return $update;
}, 10, 2);
