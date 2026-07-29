<?php
/**
 * WP Security Guard - Inspect Token Management
 *
 * 点検アクセストークンの生成・検証・管理を担当する。
 * トークンはハッシュ化してDBに保存し、平文は生成直後の1度のみ表示。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Inspect_Token {

    /** @var string DB option key (token hash) */
    const OPTION_HASH = 'wpsg_inspect_token_hash';

    /** @var string DB option key (last access info) */
    const OPTION_LAST_ACCESS = 'wpsg_inspect_last_access';

    /** @var string DB option key (allowed IP list) */
    const OPTION_ALLOWED_IPS = 'wpsg_inspect_allowed_ips';

    /** @var string Transient prefix for rate limiting */
    const RATE_LIMIT_PREFIX = 'wpsg_rl_';

    /** @var int Rate limit: max requests per window */
    const RATE_LIMIT_MAX = 60;

    /** @var int Rate limit: window in seconds */
    const RATE_LIMIT_WINDOW = 60;

    /** @var int Token length (alphanumeric chars) */
    const TOKEN_LENGTH = 64;

    /**
     * 同梱の既定トークン（複数サイト一括運用向け・低リスク項目のみ許可）。★v2.5.1
     *
     * サイト側で個別トークン(定数 or DB発行)を一切設定していなくても、
     * このトークンで core/theme/plugins/features/health の点検を通す。
     * これにより「各サイトを開いてトークンを設定する」初回作業を不要にし、
     * 自動更新で全サイトへ配布するだけで集中管理側から診断できる。
     *
     * ただし membership / stripe / all（機微情報）は対象外＝個別トークン必須。
     * 完全に無効化したい高機微サイトは wp-config で
     *   define('WPSG_DISABLE_DEFAULT_INSPECT_TOKEN', true);
     * を定義する（その場合は個別トークンのみ有効）。
     *
     * @var string
     */
    const DEFAULT_TOKEN = '9b4ca4ff1669c0534aa64a2c7512ba09a510596233aee468';

    /**
     * 同梱の既定トークンが有効か（未定数化サイトの一括診断用）。
     *
     * @return bool
     */
    public static function default_token_enabled() {
        if (self::DEFAULT_TOKEN === '') {
            return false;
        }
        if (defined('WPSG_DISABLE_DEFAULT_INSPECT_TOKEN') && WPSG_DISABLE_DEFAULT_INSPECT_TOKEN) {
            return false;
        }
        return true;
    }

    /**
     * トークンを分類する。'explicit'（個別＝定数/DB発行）/ 'default'（同梱）/ false（不正）。
     * 機微エンドポイントの許可判定は呼び出し側（REST層）が 'explicit' のみ許可する。
     *
     * @param string $token 検証する平文トークン
     * @return string|false 'explicit' | 'default' | false
     */
    public static function classify($token) {
        if (!is_string($token) || $token === '') {
            return false;
        }
        // 個別トークン：wp-config定数
        if (self::has_constant_token() && hash_equals((string) WPSG_INSPECT_TOKEN, $token)) {
            return 'explicit';
        }
        // 個別トークン：DB発行（ハッシュ照合）
        $stored_hash = get_option(self::OPTION_HASH, '');
        if (!empty($stored_hash) && wp_check_password($token, $stored_hash)) {
            return 'explicit';
        }
        // 同梱の既定トークン（低リスク項目のみ）
        if (self::default_token_enabled() && hash_equals(self::DEFAULT_TOKEN, $token)) {
            return 'default';
        }
        return false;
    }

    /**
     * 新しいトークンを生成して保存する
     * 戻り値の平文トークンは1度だけ表示すること
     *
     * @return string 平文トークン
     */
    public static function generate() {
        // 英数字のみ(URL/header送信時の互換性確保)
        $token = wp_generate_password(self::TOKEN_LENGTH, false, false);
        $hash = wp_hash_password($token);
        // autoload=falseで通常のページロード時に取得しないようにする
        update_option(self::OPTION_HASH, $hash, false);
        return $token;
    }

    /**
     * トークンを削除する
     */
    public static function revoke() {
        delete_option(self::OPTION_HASH);
    }

    /**
     * wp-config 等で共通トークンを定義しているか（複数サイト一括運用向け）。
     * define('WPSG_INSPECT_TOKEN', '...') が定義され非空なら true。
     *
     * @return bool
     */
    public static function has_constant_token() {
        return defined('WPSG_INSPECT_TOKEN') && is_string(WPSG_INSPECT_TOKEN) && WPSG_INSPECT_TOKEN !== '';
    }

    /**
     * トークンが設定されているか（定数 or DB発行のどちらか）
     *
     * @return bool
     */
    public static function is_set() {
        if (self::has_constant_token()) {
            return true;
        }
        $hash = get_option(self::OPTION_HASH, '');
        return !empty($hash);
    }

    /**
     * 提供されたトークンが正しいか検証する
     * 定数トークン(WPSG_INSPECT_TOKEN) と DB発行トークンの両方を受け付ける。
     *
     * @param string $token 検証する平文トークン
     * @return bool
     */
    public static function verify($token) {
        // 個別(explicit) / 同梱(default) いずれかに一致すれば true。
        // 機微エンドポイントの絞り込みは REST層が classify() で判定する。
        return self::classify($token) !== false;
    }

    /**
     * 最終アクセス情報を記録する
     *
     * @param string $ip アクセス元IP
     */
    public static function record_access($ip) {
        update_option(self::OPTION_LAST_ACCESS, array(
            'timestamp' => current_time('mysql'),
            'ip'        => is_string($ip) ? substr($ip, 0, 45) : '',
        ), false);
    }

    /**
     * 最終アクセス情報を取得する
     *
     * @return array|null ['timestamp' => string, 'ip' => string] または null
     */
    public static function get_last_access() {
        $access = get_option(self::OPTION_LAST_ACCESS, null);
        return is_array($access) ? $access : null;
    }

    /**
     * 許可IPリストを取得する(空文字列なら制限なし)
     *
     * @return string カンマ区切り文字列
     */
    public static function get_allowed_ips() {
        return (string) get_option(self::OPTION_ALLOWED_IPS, '');
    }

    /**
     * 許可IPリストを保存する
     *
     * @param string $ips_csv カンマ区切りIPリスト
     */
    public static function set_allowed_ips($ips_csv) {
        if (!is_string($ips_csv)) {
            $ips_csv = '';
        }
        // 各IPの簡易バリデーション
        $list = array_filter(array_map('trim', explode(',', $ips_csv)));
        $valid = array();
        foreach ($list as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $valid[] = $ip;
            }
        }
        update_option(self::OPTION_ALLOWED_IPS, implode(',', $valid), false);
    }

    /**
     * IPがアクセスを許可されているか
     *
     * @param string $ip クライアントIP
     * @return bool 許可リストが空なら常にtrue、それ以外はリスト内かどうか
     */
    public static function check_ip($ip) {
        $allowed = self::get_allowed_ips();
        if ($allowed === '') {
            return true; // 制限なし
        }
        $list = array_filter(array_map('trim', explode(',', $allowed)));
        if (empty($list)) {
            return true;
        }
        return in_array($ip, $list, true);
    }

    /**
     * レート制限チェック (IP単位)
     *
     * @param string $ip クライアントIP
     * @return bool 制限内ならtrue、超過したらfalse
     */
    public static function check_rate_limit($ip) {
        $key = self::RATE_LIMIT_PREFIX . md5((string) $ip);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT_MAX) {
            return false;
        }
        set_transient($key, $count + 1, self::RATE_LIMIT_WINDOW);
        return true;
    }

    /**
     * クライアントIPを取得する
     * X-Forwarded-For は信頼できるリバースプロキシ環境でのみ機能
     *
     * @return string IPアドレス
     */
    public static function get_client_ip() {
        // X-Forwarded-For (リバースプロキシ環境)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
            $ip = trim($forwarded[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }
        // 通常のリモートアドレス
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }
        return '';
    }
}
