<?php
/*
Plugin Name: WP Security Guard
Plugin URI: https://github.com/RemakeDevTeam/wp-security-guard
Description: 統合セキュリティプラグイン。XML-RPC遮断・ユーザー名列挙対策・バージョン情報隠蔽・アプリケーションパスワード無効化・Contact Form 7 スパム対策・サイト点検モジュール(機能フラグ管理・会員管理・決済設定inspector)を1プラグインで管理します。自己ホスト更新(GitHub)対応。
Version: 2.5.3
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
    }

    public function sanitize_options($input) {
        $clean = array();

        $yn_keys = array(
            'block_xmlrpc', 'block_rest_users', 'block_author_query',
            'unify_login_errors', 'hide_wp_version', 'disable_app_passwords',
            'enable_cf7_guard', 'check_sender'
        );
        foreach ($yn_keys as $k) {
            $clean[$k] = (isset($input[$k]) && $input[$k] === 'yes') ? 'yes' : 'no';
        }

        $clean['message_field']      = isset($input['message_field']) ? sanitize_text_field($input['message_field']) : 'your-message';
        $clean['sender_field']       = isset($input['sender_field'])  ? sanitize_text_field($input['sender_field'])  : 'your-name';
        $clean['max_urls']           = isset($input['max_urls'])           ? max(0, intval($input['max_urls']))           : 3;
        $clean['min_japanese_chars'] = isset($input['min_japanese_chars']) ? max(0, intval($input['min_japanese_chars'])) : 5;

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
