<?php
/**
 * WP Security Guard - SEO Guard 署名検証
 *
 * 検索対策モジュールの「書き込み」系エンドポイントの認証を担当する。
 *
 * 【なぜトークンではなく公開鍵署名なのか】
 * 本プラグインは公開リポジトリで配布されており、同梱の既定トークン
 * (WPSG_Inspect_Token::DEFAULT_TOKEN) はソースを読めば誰でも入手できる。
 * したがって書き込みを既定トークンに許すことはできない。
 * かといって各サイトへ個別トークンを設定して回るのは運用上現実的でない
 * (69サイトの手作業になる)。
 *
 * そこで Ed25519 の公開鍵署名を使う。
 *   - 集中管理側(WP Site Manager)が秘密鍵を保持し、リクエストに署名する
 *   - 各サイトは公開鍵だけを持ち、署名を検証する
 * ソースが公開されていても得られるのは公開鍵のみで、書き込みはできない。
 * かつサイト側の設定作業はゼロで済む(自動更新で配布するだけ)。
 *
 * @package WPSecurityGuard
 * @subpackage SeoGuard
 * @since 2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_SEO_Signature {

    /**
     * 集中管理側(WP Site Manager)の署名検証用 公開鍵 (hex)。
     * 公開情報。これだけでは署名を作れないため、リポジトリに含めて問題ない。
     * 鍵を差し替える場合は wp-config で define('WPSG_SEO_PUBKEY', '...') により上書き可。
     *
     * @var string
     */
    const PUBLIC_KEY_HEX = '700104fa266218eec8bb87672fc7bd0446848fffa6ec2aac257c1ae5791fd572';

    /** @var int 署名の有効期間(秒)。これを過ぎたリクエストは拒否する。 */
    const TTL = 60;

    /** @var int 許容する時計ズレ(秒)。未来方向のtimestampもこの範囲までは許す。 */
    const MAX_SKEW = 300;

    /** @var string 使用済みnonceを記録するtransientの接頭辞。 */
    const NONCE_PREFIX = 'wpsg_seo_nonce_';

    /** @var int nonceを記録しておく秒数(TTL + MAX_SKEW より十分長く取る)。 */
    const NONCE_TTL = 900;

    /**
     * sodium(Ed25519)が利用可能か。
     * 利用できない環境では書き込みを一切許可しない(安全側に倒す)。
     *
     * @return bool
     */
    public static function available() {
        return function_exists('sodium_crypto_sign_verify_detached')
            && function_exists('sodium_hex2bin');
    }

    /**
     * このサイトで SEO Guard の書き込みが無効化されているか。
     * wp-config で define('WPSG_DISABLE_SEO_WRITE', true) を定義すると完全遮断する。
     *
     * @return bool
     */
    public static function write_disabled() {
        return defined('WPSG_DISABLE_SEO_WRITE') && WPSG_DISABLE_SEO_WRITE;
    }

    /**
     * 検証に使う公開鍵(hex)を返す。
     *
     * @return string
     */
    public static function public_key_hex() {
        if (defined('WPSG_SEO_PUBKEY') && is_string(WPSG_SEO_PUBKEY) && WPSG_SEO_PUBKEY !== '') {
            return (string) WPSG_SEO_PUBKEY;
        }
        return self::PUBLIC_KEY_HEX;
    }

    /**
     * 署名対象の正規化文字列を組み立てる。
     *
     * 構成要素と目的:
     *   method     … メソッドの取り違えを防ぐ
     *   route      … 別エンドポイントへの使い回しを防ぐ
     *   site_url   … 他サイト宛の署名を使い回せないようにする(最重要)
     *   timestamp  … 有効期限による失効
     *   nonce      … 再送(リプレイ)の検出
     *   body hash  … ボディ改ざんの検出
     *
     * @param string $method    HTTPメソッド。
     * @param string $route     RESTルート。
     * @param string $timestamp UNIX秒。
     * @param string $nonce     ランダム文字列。
     * @param string $body      リクエストボディ(生)。
     * @return string
     */
    public static function canonical($method, $route, $timestamp, $nonce, $body) {
        return implode("\n", array(
            strtoupper((string) $method),
            (string) $route,
            self::site_identity(),
            (string) $timestamp,
            (string) $nonce,
            hash('sha256', (string) $body),
        ));
    }

    /**
     * 署名に含めるサイト識別子。
     * home_url() は末尾スラッシュ有無・スキームで揺れるので正規化する。
     *
     * @return string
     */
    public static function site_identity() {
        $url = home_url('/');
        $url = preg_replace('#^https?://#i', '', (string) $url);
        return rtrim((string) $url, '/');
    }

    /**
     * リクエストの署名を検証する。
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public static function verify($request) {
        if (self::write_disabled()) {
            return new WP_Error(
                'seo_write_disabled',
                'SEO write operations are disabled on this site (WPSG_DISABLE_SEO_WRITE).',
                array('status' => 403)
            );
        }

        if (!self::available()) {
            return new WP_Error(
                'sodium_unavailable',
                'sodium (Ed25519) is not available on this server; write operations are refused.',
                array('status' => 501)
            );
        }

        $timestamp = (string) $request->get_header('x-wpsg-timestamp');
        $nonce     = (string) $request->get_header('x-wpsg-nonce');
        $signature = (string) $request->get_header('x-wpsg-signature');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return new WP_Error(
                'signature_missing',
                'X-WPSG-Timestamp, X-WPSG-Nonce and X-WPSG-Signature headers are required.',
                array('status' => 401)
            );
        }

        // nonce は長さを制限(transientキー肥大とDoSの抑止)
        if (strlen($nonce) < 16 || strlen($nonce) > 128) {
            return new WP_Error('nonce_invalid', 'Invalid nonce.', array('status' => 400));
        }

        // 有効期限。過去は TTL 秒、未来は時計ズレ分だけ許容する。
        $now  = time();
        $ts   = (int) $timestamp;
        $diff = $now - $ts;
        if ($diff > self::TTL || $diff < -self::MAX_SKEW) {
            return new WP_Error(
                'signature_expired',
                'Signature timestamp is outside the accepted window.',
                array('status' => 401)
            );
        }

        // リプレイ検出。同じ nonce の2度目は拒否する。
        $nonce_key = self::NONCE_PREFIX . md5($nonce);
        if (get_transient($nonce_key) !== false) {
            return new WP_Error(
                'nonce_reused',
                'This nonce has already been used.',
                array('status' => 401)
            );
        }

        $public_key_hex = self::public_key_hex();
        if ($public_key_hex === '' || strlen($public_key_hex) !== 64) {
            return new WP_Error(
                'pubkey_invalid',
                'Verification public key is not configured correctly.',
                array('status' => 500)
            );
        }

        $body      = (string) $request->get_body();
        $canonical = self::canonical(
            $request->get_method(),
            $request->get_route(),
            $timestamp,
            $nonce,
            $body
        );

        $sig_bin = base64_decode($signature, true);
        if ($sig_bin === false || strlen($sig_bin) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return new WP_Error('signature_malformed', 'Malformed signature.', array('status' => 400));
        }

        try {
            $pk_bin = sodium_hex2bin($public_key_hex);
            $ok     = sodium_crypto_sign_verify_detached($sig_bin, $canonical, $pk_bin);
        } catch (Exception $e) {
            return new WP_Error('signature_error', 'Signature verification failed.', array('status' => 400));
        }

        if (!$ok) {
            return new WP_Error(
                'signature_invalid',
                'Signature verification failed.',
                array('status' => 403)
            );
        }

        // 検証成功後に nonce を記録する(失敗リクエストで nonce を使い潰さない)。
        set_transient($nonce_key, 1, self::NONCE_TTL);

        return true;
    }
}
