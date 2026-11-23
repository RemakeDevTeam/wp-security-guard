<?php
/**
 * WP Security Guard - MailPoet Inspector
 *
 * MailPoet（3以降）の設定を独自テーブル {prefix}mailpoet_settings から取得する。
 *   - 'mta' 行（serialize配列）＝ニュースレターの送信方式（method='SMTP' の時に SMTP 設定）
 *   - 'send_transactional_emails' 行（スカラー）＝「すべてのサイトのメールを送信…」。
 *     空/0/行なし → デフォルトの WordPress 送信方法（既定）／ '1' → MailPoet が全 wp_mail を肩代わり。
 *
 * パスワード（mta['password'] は DB 内平文）・APIキーは真偽のみ。配列は丸ごと返さない。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.10.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Mail_MailPoet extends WPSG_Mail_Inspector_Base {

    const PLUGIN = 'mailpoet/mailpoet.php';

    public function is_active() {
        return $this->is_plugin_active(self::PLUGIN);
    }

    public function get_implementation_id() {
        return 'mailpoet';
    }

    public function get_data() {
        $table = $this->wpdb()->prefix . 'mailpoet_settings';

        // 有効化直後など、テーブル未作成のケースを弾く。
        if (!$this->table_exists($table)) {
            $this->add_warning(
                'notice',
                'mailpoet_settings_table_missing',
                'MailPoet は有効ですが設定テーブルが見つかりません（半インストール/有効化直後の可能性）',
                array('table' => $table)
            );
            return $this->build_response(array(
                'version' => $this->get_plugin_version(self::PLUGIN),
                'custom'  => array('settings_readable' => false),
            ));
        }

        $mta                = $this->read_setting($table, 'mta');
        $mta                = is_array($mta) ? $mta : array();
        $method             = $this->find_value($mta, array('method')); // 'SMTP'|'PHPMail'|'MailPoet'|...
        $transactional_raw  = $this->read_setting($table, 'send_transactional_emails');

        // 「デフォルトの WordPress 送信方法か」。empty() が truthy 判定と一致する。
        //   行なし/''/'0'/0/null → true（デフォルトWP）／ '1'/1 → false（MailPoet が肩代わり）
        $default_wp_mailer = empty($transactional_raw);

        $is_smtp = ($method === 'SMTP');

        return $this->build_response(array(
            'version'      => $this->get_plugin_version(self::PLUGIN),
            'mailer'       => $method !== null ? $method : 'unknown',
            'host'         => $is_smtp ? $this->find_value($mta, array('host')) : null,
            'port'         => $is_smtp ? $this->find_value($mta, array('port')) : null,
            'encryption'   => $is_smtp ? $this->find_value($mta, array('encryption')) : null,
            'auth'         => $is_smtp ? (bool) $this->find_value($mta, array('authentication')) : null,
            'username'     => $is_smtp ? $this->find_value($mta, array('login', 'username')) : null,
            'password_set' => $this->is_set($this->find_value($mta, array('password'))), // ★真偽のみ（DB平文のため厳守）
            'settings_url' => admin_url('admin.php?page=mailpoet-settings'),
            'custom'       => array(
                // ↓ 第3問の答え。ニュースレター送信方式（mailer/mta）とは別設定。
                'default_wp_mailer'    => $default_wp_mailer,
                'newsletter_method'    => $method,
                'mta_group'            => $this->read_setting($table, 'mta_group'),
                'has_mailpoet_api_key' => $this->is_set($this->find_value($mta, array('mailpoet_api_key'))),
            ),
        ));
    }

    /**
     * {prefix}mailpoet_settings から name の value を取得（配列は unserialize）。
     *
     * @param string $table 完全なテーブル名
     * @param string $name  設定名
     * @return mixed|null
     */
    private function read_setting($table, $name) {
        $wpdb = $this->wpdb();
        // テーブル名はプレースホルダ不可のため、$wpdb->prefix + 定数で構築済みの $table を直接埋め込む。
        $raw = $wpdb->get_var($wpdb->prepare("SELECT value FROM {$table} WHERE name = %s", $name));
        if ($raw === null) {
            return null;
        }
        return maybe_unserialize($raw);
    }
}
