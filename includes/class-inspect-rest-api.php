<?php
/**
 * WP Security Guard - Inspect REST API
 *
 * RESTエンドポイントの登録と権限チェックを担当する。
 * すべてのエンドポイントは Bearer トークン認証を必要とする(/health を除く)。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Inspect_Rest_Api {

    /**
     * REST APIフックを登録する
     */
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    /**
     * エンドポイントの登録
     */
    public static function register_routes() {
        // health は認証不要 (軽量稼働確認用)
        register_rest_route(WPSG_INSPECTOR_REST_NAMESPACE, '/inspect/health', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'route_health'),
            'permission_callback' => '__return_true',
        ));

        // 認証必須エンドポイント
        $authenticated = array(
            'all'        => 'route_all',
            'core'       => 'route_core',
            'theme'      => 'route_theme',
            'plugins'    => 'route_plugins',
            'features'   => 'route_features',
            'membership' => 'route_membership',
            'stripe'     => 'route_stripe',
        );
        foreach ($authenticated as $path => $method) {
            register_rest_route(WPSG_INSPECTOR_REST_NAMESPACE, '/inspect/' . $path, array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array(__CLASS__, $method),
                'permission_callback' => array(__CLASS__, 'check_permission'),
            ));
        }
    }

    /**
     * 認証チェック (レート制限・IP制限・トークン検証)
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function check_permission($request) {
        $ip = WPSG_Inspect_Token::get_client_ip();

        // レート制限
        if (!WPSG_Inspect_Token::check_rate_limit($ip)) {
            return new WP_Error(
                'rate_limit_exceeded',
                'Too many requests. Please retry later.',
                array('status' => 429)
            );
        }

        // IP制限
        if (!WPSG_Inspect_Token::check_ip($ip)) {
            return new WP_Error(
                'ip_not_allowed',
                'Access denied.',
                array('status' => 403)
            );
        }

        // トークン抽出
        $auth_header = $request->get_header('Authorization');
        if (empty($auth_header) || !preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
            return new WP_Error(
                'unauthorized',
                'Authorization header required.',
                array('status' => 401)
            );
        }
        $provided_token = trim($matches[1]);

        // トークン検証＋分類（explicit=個別 / default=同梱）
        $class = WPSG_Inspect_Token::classify($provided_token);
        if ($class === false) {
            return new WP_Error(
                'invalid_token',
                'Access denied.',
                array('status' => 403)
            );
        }

        // 同梱(既定)トークンは低リスク項目のみ許可。
        // membership / stripe / all（機微情報）は個別トークン必須。★v2.5.1
        if ($class === 'default') {
            $route = (string) $request->get_route();
            $sensitive = array('/membership', '/stripe', '/all');
            foreach ($sensitive as $suffix) {
                if (substr($route, -strlen($suffix)) === $suffix) {
                    return new WP_Error(
                        'token_scope',
                        'The shared token cannot access sensitive endpoints. Configure a site-specific token (WPSG_INSPECT_TOKEN or generate one).',
                        array('status' => 403)
                    );
                }
            }
        }

        // 認証成功 → アクセス記録
        WPSG_Inspect_Token::record_access($ip);
        return true;
    }

    // ========================================================================
    // ルートハンドラー
    // ========================================================================

    /**
     * GET /wpsg/v1/inspect/health
     * 認証不要・軽量ヘルスチェック
     */
    public static function route_health($request) {
        return self::success(array(
            'status'             => 'ok',
            'wp_version'         => get_bloginfo('version'),
            'inspector_version'  => WPSG_Site_Inspector::get_version(),
            'token_configured'   => WPSG_Inspect_Token::is_set(),
        ));
    }

    /**
     * GET /wpsg/v1/inspect/all
     * すべての項目を一括取得
     */
    public static function route_all($request) {
        $core_inspector       = new WPSG_Inspector_Core();
        $theme_inspector      = new WPSG_Inspector_Theme();
        $plugins_inspector    = new WPSG_Inspector_Plugins();
        $features_inspector   = new WPSG_Inspector_Features();
        $membership_inspector = new WPSG_Inspector_Membership();
        $stripe_inspector     = new WPSG_Inspector_Stripe();

        $core_result       = self::run_inspector($core_inspector);
        $theme_result      = self::run_inspector($theme_inspector);
        $plugins_result    = self::run_inspector($plugins_inspector);
        $features_result   = self::run_inspector($features_inspector);
        $membership_result = self::run_inspector($membership_inspector);
        $stripe_result     = self::run_inspector($stripe_inspector);

        $warnings = array_merge(
            $core_result['warnings'],
            $theme_result['warnings'],
            $plugins_result['warnings'],
            $features_result['warnings'],
            $membership_result['warnings'],
            $stripe_result['warnings']
        );

        return self::success(array(
            'core'       => $core_result['data'],
            'theme'      => $theme_result['data'],
            'plugins'    => $plugins_result['data'],
            'features'   => $features_result['data'],
            'membership' => $membership_result['data'],
            'stripe'     => $stripe_result['data'],
        ), $warnings);
    }

    /**
     * GET /wpsg/v1/inspect/core
     */
    public static function route_core($request) {
        $result = self::run_inspector(new WPSG_Inspector_Core());
        return self::success($result['data'], $result['warnings']);
    }

    /**
     * GET /wpsg/v1/inspect/theme
     */
    public static function route_theme($request) {
        $result = self::run_inspector(new WPSG_Inspector_Theme());
        return self::success($result['data'], $result['warnings']);
    }

    /**
     * GET /wpsg/v1/inspect/plugins
     */
    public static function route_plugins($request) {
        $result = self::run_inspector(new WPSG_Inspector_Plugins());
        return self::success($result['data'], $result['warnings']);
    }

    /**
     * GET /wpsg/v1/inspect/features
     */
    public static function route_features($request) {
        $result = self::run_inspector(new WPSG_Inspector_Features());
        return self::success($result['data'], $result['warnings']);
    }

    /**
     * GET /wpsg/v1/inspect/membership
     */
    public static function route_membership($request) {
        $result = self::run_inspector(new WPSG_Inspector_Membership());
        return self::success($result['data'], $result['warnings']);
    }

    /**
     * GET /wpsg/v1/inspect/stripe
     */
    public static function route_stripe($request) {
        $result = self::run_inspector(new WPSG_Inspector_Stripe());
        return self::success($result['data'], $result['warnings']);
    }

    // ========================================================================
    // ヘルパー
    // ========================================================================

    /**
     * inspectorを実行し、例外を捕捉して結果を返す
     *
     * @param WPSG_Inspector_Base $inspector
     * @return array ['data' => array, 'warnings' => array]
     */
    private static function run_inspector($inspector) {
        try {
            $data = $inspector->inspect();
            $warnings = $inspector->get_warnings();
            return array('data' => $data, 'warnings' => $warnings);
        } catch (Exception $e) {
            return array(
                'data' => array(),
                'warnings' => array(array(
                    'level'   => 'error',
                    'code'    => 'inspector_exception',
                    'message' => 'Inspector failed: ' . $e->getMessage(),
                )),
            );
        }
    }

    /**
     * 成功レスポンスの共通フォーマット
     *
     * @param array $data 主データ
     * @param array $warnings 警告配列
     * @return WP_REST_Response
     */
    private static function success($data, $warnings = array()) {
        return new WP_REST_Response(array(
            'success'    => true,
            'checked_at' => current_time('c'), // ISO 8601 with timezone
            'data'       => $data,
            'warnings'   => array_values($warnings),
        ), 200);
    }
}
