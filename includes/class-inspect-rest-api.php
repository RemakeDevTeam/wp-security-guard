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

        // ------------------------------------------------------------------
        // SEO Guard（検索対策）★v2.9.0
        // 読み取り(scan/batches)はトークン認証。書き込み(apply/rollback)は
        // Ed25519 の公開鍵署名を必須とする（同梱トークンでは書き込めない）。
        // ------------------------------------------------------------------
        register_rest_route(WPSG_INSPECTOR_REST_NAMESPACE, '/seo/scan', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'route_seo_scan'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));
        register_rest_route(WPSG_INSPECTOR_REST_NAMESPACE, '/seo/batches', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'route_seo_batches'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));
        register_rest_route(WPSG_INSPECTOR_REST_NAMESPACE, '/seo/apply', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'route_seo_apply'),
            'permission_callback' => array(__CLASS__, 'check_signed_permission'),
        ));
        register_rest_route(WPSG_INSPECTOR_REST_NAMESPACE, '/seo/rollback', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'route_seo_rollback'),
            'permission_callback' => array(__CLASS__, 'check_signed_permission'),
        ));
    }

    /**
     * 書き込み系の権限チェック。★v2.9.0
     *
     * トークン認証（レート制限・IP制限を含む）を通したうえで、さらに
     * 集中管理側の秘密鍵による署名を必須とする。
     * 同梱トークンは公開リポジトリから入手できるため、これ単体では書き込ませない。
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function check_signed_permission($request) {
        $base = self::check_permission($request);
        if (is_wp_error($base)) {
            return $base;
        }
        return WPSG_SEO_Signature::verify($request);
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

        // 同梱(既定)/共通トークンは低リスク項目のみ許可。
        // membership / all（機微情報）は個別トークン必須。★v2.5.1
        // stripe は「モード概要(test/live)のみ」を既定トークンでも許可し、鍵・webhook等の
        // 機微詳細は route_stripe 側で redact する（個別トークンのみ全項目）。★v2.7.0
        if ($class === 'default') {
            $route = (string) $request->get_route();
            $sensitive = array('/membership', '/all');
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
     * 個別(explicit)トークン=全項目。既定/共通トークン=モード概要(test/live)のみ。★v2.7.0
     */
    public static function route_stripe($request) {
        $result = self::run_inspector(new WPSG_Inspector_Stripe());
        $data   = $result['data'];
        if (self::token_class($request) !== 'explicit') {
            $data = self::redact_stripe_to_mode($data);
        }
        return self::success($data, $result['warnings']);
    }

    /**
     * リクエストの Bearer トークンを分類する。★v2.7.0
     *
     * @param WP_REST_Request $request
     * @return string|false 'explicit' | 'default' | false
     */
    private static function token_class($request) {
        $auth = $request->get_header('Authorization');
        if (!empty($auth) && preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return WPSG_Inspect_Token::classify(trim($m[1]));
        }
        return false;
    }

    /**
     * Stripe結果を「モード概要のみ」に絞る（鍵の有無・webhook・通貨・実装詳細を除去）。★v2.7.0
     * 既定/共通トークン向け。test/live の運用監視に必要な最小限だけを返す。
     *
     * @param array $data inspect_all() の結果。
     * @return array
     */
    private static function redact_stripe_to_mode($data) {
        if (!is_array($data)) {
            return array('redacted' => true);
        }
        $summary = isset($data['summary']) && is_array($data['summary']) ? $data['summary'] : array();
        $mc      = isset($data['mode_consistency']) && is_array($data['mode_consistency']) ? $data['mode_consistency'] : array();
        return array(
            'redacted'         => true, // モードのみ（機微詳細は個別トークンで /stripe を叩くと取得可）
            'implementations'  => isset($data['implementations']) ? array_values((array) $data['implementations']) : array(),
            'summary'          => array(
                'implementations_count' => (int) ($summary['implementations_count'] ?? 0),
                'live_implementations'  => (int) ($summary['live_implementations'] ?? 0),
                'test_implementations'  => (int) ($summary['test_implementations'] ?? 0),
                'configured_only'       => (int) ($summary['configured_only'] ?? 0),
            ),
            'mode_consistency' => array(
                'consistent' => ! empty($mc['consistent']),
                'warning'    => isset($mc['warning']) ? $mc['warning'] : null,
            ),
        );
    }

    // ========================================================================
    // SEO Guard（検索対策）★v2.9.0
    // ========================================================================

    /**
     * GET /wpsg/v1/seo/scan
     *
     * 現状把握のみ（書き込みなし）。会社名・代表者名はクエリで受け取る。
     *   ?company=アルイトコー&rep=山田 太郎
     */
    public static function route_seo_scan($request) {
        $company = (string) $request->get_param('company');
        $rep     = (string) $request->get_param('rep');
        $result  = self::run_inspector(new WPSG_Inspector_Seo($company, $rep));
        return self::success($result['data'], $result['warnings']);
    }

    /**
     * GET /wpsg/v1/seo/batches
     * 復元可能な適用バッチの一覧。
     */
    public static function route_seo_batches($request) {
        return self::success(array('batches' => WPSG_SEO_Guard::batches()));
    }

    /**
     * POST /wpsg/v1/seo/apply
     *
     * ボディ: {"dry_run": true, "ops": [...]}
     * dry_run は既定 true。false を明示したときのみ書き込む。
     */
    public static function route_seo_apply($request) {
        $body = json_decode((string) $request->get_body(), true);
        if (!is_array($body)) {
            return new WP_Error('bad_body', 'Request body must be a JSON object.', array('status' => 400));
        }
        // 既定は必ず dry_run。false の明示指定があるときだけ書き込む。
        $dry_run = !(isset($body['dry_run']) && $body['dry_run'] === false);
        $ops     = isset($body['ops']) ? $body['ops'] : array();

        $result = WPSG_SEO_Guard::apply($ops, $dry_run);
        if (is_wp_error($result)) {
            return $result;
        }
        return self::success($result);
    }

    /**
     * POST /wpsg/v1/seo/rollback
     * ボディ: {"batch_id": "b20260815..."}
     */
    public static function route_seo_rollback($request) {
        $body     = json_decode((string) $request->get_body(), true);
        $batch_id = is_array($body) && isset($body['batch_id']) ? (string) $body['batch_id'] : '';
        if ($batch_id === '') {
            return new WP_Error('bad_body', 'batch_id is required.', array('status' => 400));
        }
        $result = WPSG_SEO_Guard::rollback($batch_id);
        if (is_wp_error($result)) {
            return $result;
        }
        return self::success($result);
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
