<?php
/**
 * 自己更新パッケージの署名確認。★v2.10.0
 *
 * このプラグインは GitHub のリリースから全サイトへ自動更新で配られる。
 * GitHub のアカウントやトークンが盗まれると、全サイトへ悪いコードを一斉に
 * 入れられてしまう（配布元が狙われる型。UpdraftCentral の接続キー流出と同じ構図）。
 *
 * そこで、このプラグイン自身の更新に限り、入れる直前に zip をダウンロードして
 * 集中管理側（WPSM）の秘密鍵による Ed25519 署名を確かめる。確かめられなければ
 * 更新を中止し、今の版のまま動き続ける。GitHub を乗っ取られても、秘密鍵が無ければ
 * 署名を作れないので、悪い更新は入らない。
 *
 * 署名ファイル（リリースに zip と並べて置く「<zip名>.sig」）は JSON：
 *   {"v":1, "version":"2.10.1", "sha256":"<zipのsha256>", "sig":"<base64>", "key":"<公開鍵hex>"}
 * 署名の対象は「wpsg-update-v1\n<version>\n<sha256>」。
 *
 * 確かめること：
 *   1. 署名ファイルがある
 *   2. zip の sha256 が署名ファイルの値と一致する（中身の書き換えを検出）
 *   3. 同梱の公開鍵のどれかで署名が正しい（秘密鍵を持つ人だけが作れる）
 *   4. 署名された版が今の版より新しい（古い署名済み zip での巻き戻しを拒否）
 *   5. 更新情報の版がわかるときは、それと一致する
 *
 * 公開鍵は複数持てる（予備の鍵での入れ替えのため）。wp-config の
 * define('WPSG_UPDATE_PUBKEYS', 'hex1,hex2') で追加できる（管理者だけが書ける場所）。
 * 非常時に確認を外すには define('WPSG_UPDATE_SKIP_VERIFY', true)。
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Update_Verify {

    /** 署名する文字列の先頭（WPSM 側と一致させる）。 */
    const PREFIX = 'wpsg-update-v1';

    /**
     * 同梱の公開鍵（hex）。1つ目＝WPSM の署名鍵。2つ目以降＝予備（入れ替え用）。
     */
    const PUBLIC_KEYS = array(
        '527781499cf88372ad52ba9669369174c64b5c0502ca351a67c804e230385cc0', // WPSM の署名鍵（option wpsm_wpsg_update_sign_key）
        '849e7a9b84f456bc08aae41d009b00268c3ecf794ebdec6f5911186eaa1ee574', // 予備の鍵（パスフレーズ付きで Mac の外に保管・2026-10-24 作成）
    );

    /** 直近の確認結果を残す option（WPSM が点検で読む）。 */
    const OPTION_LAST = 'wpsg_update_verify_last';

    public static function init() {
        add_filter('upgrader_pre_download', array(__CLASS__, 'pre_download'), 10, 4);
    }

    /** このプラグインの basename（wp-security-guard/wp-security-guard.php）。 */
    public static function basename() {
        return plugin_basename(WPSG_MAIN_FILE);
    }

    /** 今入っている版（見出しの Version）。 */
    public static function current_version() {
        if (!function_exists('get_file_data')) {
            require_once ABSPATH . 'wp-includes/functions.php';
        }
        $d = get_file_data(WPSG_MAIN_FILE, array('Version' => 'Version'));
        return isset($d['Version']) ? (string) $d['Version'] : '';
    }

    /** 確認に使う公開鍵（バイナリ）の一覧。 */
    public static function public_keys() {
        $hexes = self::PUBLIC_KEYS;
        if (defined('WPSG_UPDATE_PUBKEYS') && is_string(WPSG_UPDATE_PUBKEYS) && WPSG_UPDATE_PUBKEYS !== '') {
            $hexes = array_merge($hexes, array_map('trim', explode(',', WPSG_UPDATE_PUBKEYS)));
        }
        $out = array();
        foreach ($hexes as $h) {
            if (is_string($h) && preg_match('/^[0-9a-f]{64}$/i', $h)) {
                $out[] = hex2bin(strtolower($h));
            }
        }
        return $out;
    }

    /** 更新情報（WordPress が持つ）に書かれた、これから入る版。分からなければ空。 */
    private static function expected_version() {
        $t = get_site_transient('update_plugins');
        $b = self::basename();
        if (is_object($t) && isset($t->response[$b]) && is_object($t->response[$b]) && !empty($t->response[$b]->new_version)) {
            return ltrim((string) $t->response[$b]->new_version, 'vV');
        }
        return '';
    }

    /** 結果を残す。 */
    private static function record($result, $version, $reason) {
        update_option(self::OPTION_LAST, array(
            'at'      => current_time('mysql'),
            'result'  => $result,   // ok | ng | skip
            'version' => (string) $version,
            'reason'  => (string) $reason,
        ), false);
    }

    /**
     * 直近の確認結果（点検 API 用）。
     *
     * @return array|null
     */
    public static function last() {
        $v = get_option(self::OPTION_LAST, null);
        return is_array($v) ? $v : null;
    }

    /**
     * 署名ファイルの URL。リリースの zip と同じ場所の「<zip名>.sig」。
     *
     * @param string $package zip の URL。
     */
    public static function signature_url($package) {
        $url = preg_replace('/\?.*$/', '', (string) $package) . '.sig';
        return (string) apply_filters('wpsg_update_signature_url', $url, $package);
    }

    /**
     * WordPress が更新パッケージをダウンロードする直前のフック。
     * このプラグインの更新のときだけ、自分でダウンロードして署名を確かめ、
     * 確かめた zip のパスを返す（WordPress はそれを使う）。
     *
     * @return mixed false（他に任せる）／zip のパス／WP_Error（更新中止）
     */
    public static function pre_download($reply, $package, $upgrader = null, $hook_extra = array()) {
        if ($reply !== false) {
            return $reply;
        }
        if (!is_array($hook_extra) || !isset($hook_extra['plugin']) || $hook_extra['plugin'] !== self::basename()) {
            return $reply;
        }
        if (defined('WPSG_UPDATE_SKIP_VERIFY') && WPSG_UPDATE_SKIP_VERIFY) {
            self::record('skip', '', 'WPSG_UPDATE_SKIP_VERIFY により確認を省略');
            return $reply;
        }
        $res = self::verify_package((string) $package);
        if (is_wp_error($res)) {
            return $res;
        }
        return $res; // 確かめた zip のパス
    }

    /**
     * パッケージを取得して確かめる。
     *
     * @param string $package zip の URL。
     * @return string|WP_Error 確かめた zip のパス、または更新中止の理由。
     */
    public static function verify_package($package) {
        $fail = function ($code, $msg, $version = '', $file = '') {
            if ($file !== '' && file_exists($file)) {
                @unlink($file);
            }
            self::record('ng', $version, $msg);
            return new WP_Error($code, 'WP Security Guard の更新を中止しました（署名を確かめられません）：' . $msg);
        };

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return $fail('wpsg_update_no_sodium', 'このサーバーでは署名を確認できません（sodium なし）');
        }
        $keys = self::public_keys();
        if (empty($keys)) {
            return $fail('wpsg_update_no_key', '確認用の公開鍵がありません');
        }

        // 1) 署名ファイル
        $sig_url = self::signature_url($package);
        $resp    = wp_remote_get($sig_url, array('timeout' => 30, 'redirection' => 5));
        if (is_wp_error($resp)) {
            return $fail('wpsg_update_sig_fetch', '署名ファイルを取得できません（' . $resp->get_error_message() . '）');
        }
        if ((int) wp_remote_retrieve_response_code($resp) !== 200) {
            return $fail('wpsg_update_sig_missing', '署名ファイルがありません（HTTP ' . (int) wp_remote_retrieve_response_code($resp) . '）');
        }
        $meta = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($meta) || (int) ($meta['v'] ?? 0) !== 1 || empty($meta['version']) || empty($meta['sha256']) || empty($meta['sig'])) {
            return $fail('wpsg_update_sig_format', '署名ファイルの形式が正しくありません');
        }
        $version = ltrim((string) $meta['version'], 'vV');
        $sha     = strtolower((string) $meta['sha256']);
        if (!preg_match('/^\d+(\.\d+)+$/', $version) || !preg_match('/^[0-9a-f]{64}$/', $sha)) {
            return $fail('wpsg_update_sig_format', '署名ファイルの値が正しくありません', $version);
        }

        // 4) 5) 版（巻き戻しと取り違えの拒否）
        $current = self::current_version();
        if ($current !== '' && version_compare($version, $current, '<=')) {
            return $fail('wpsg_update_downgrade', '署名された版 ' . $version . ' が今の版 ' . $current . ' より新しくありません', $version);
        }
        $expected = self::expected_version();
        if ($expected !== '' && version_compare($expected, $version, '!=')) {
            return $fail('wpsg_update_version_mismatch', '署名の版 ' . $version . ' と更新情報の版 ' . $expected . ' が違います', $version);
        }

        // 3) 署名（ダウンロード前に確かめる。中身を落とす前に偽物を弾く）
        $sig = base64_decode((string) $meta['sig'], true);
        if ($sig === false || strlen($sig) !== 64) {
            return $fail('wpsg_update_sig_format', '署名の値が正しくありません', $version);
        }
        $message = self::PREFIX . "\n" . $version . "\n" . $sha;
        $ok      = false;
        foreach ($keys as $pk) {
            try {
                if (sodium_crypto_sign_verify_detached($sig, $message, $pk)) {
                    $ok = true;
                    break;
                }
            } catch (\Throwable $e) {
                // 鍵の形式不正などは「合わない」として扱う
            }
        }
        if (!$ok) {
            return $fail('wpsg_update_bad_sig', '署名が正しくありません（同梱の鍵で作られたものではない）', $version);
        }

        // 2) zip 本体を取得し、sha256 を照合
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = download_url($package, 300);
        if (is_wp_error($file)) {
            return $fail('wpsg_update_download', '更新ファイルを取得できません（' . $file->get_error_message() . '）', $version);
        }
        $actual = hash_file('sha256', $file);
        if (!is_string($actual) || !hash_equals($sha, strtolower($actual))) {
            return $fail('wpsg_update_hash_mismatch', '更新ファイルの中身が署名と一致しません（書き換えの疑い）', $version, $file);
        }

        self::record('ok', $version, '署名を確認しました');
        return $file;
    }
}
