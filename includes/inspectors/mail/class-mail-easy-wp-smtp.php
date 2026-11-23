<?php
/**
 * WP Security Guard - Easy WP SMTP Inspector
 *
 * Easy WP SMTP の SMTP 設定を取得する（機微はマスク）。
 *
 * 本体ファイルパスは2系統とも同じ（easy-wp-smtp/easy-wp-smtp.php）なので
 * is_plugin_active() では系統を区別できない。版（>=2.0）または名前空間クラス
 * \EasyWPSMTP\Core の有無で v2 / 旧系（legacy）を確定してから対応オプションを読む。
 *   - v2系 : option 'easy_wp_smtp' の ['smtp'] 群 ＋ ['mail']['mailer']
 *   - 旧系 : option 'swpsmtp_options' の ['smtp_settings'] 群
 * パスワードは真偽のみ（base64/Crypto いずれも復号しない）。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.10.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Mail_Easy_WP_SMTP extends WPSG_Mail_Inspector_Base {

    const PLUGIN = 'easy-wp-smtp/easy-wp-smtp.php';

    public function is_active() {
        return $this->is_plugin_active(self::PLUGIN);
    }

    public function get_implementation_id() {
        return 'easy-wp-smtp';
    }

    public function get_data() {
        if ($this->is_v2()) {
            return $this->get_v2_data();
        }
        return $this->get_legacy_data();
    }

    /**
     * v2系（Awesome Motive 版・2.0 以降）かどうか。
     * 版 >= 2.0 または \EasyWPSMTP\Core クラスの存在で判定。
     *
     * @return bool
     */
    private function is_v2() {
        if (class_exists('\\EasyWPSMTP\\Core') || class_exists('\\EasyWPSMTP\\Options')) {
            return true;
        }
        $ver = $this->get_plugin_version(self::PLUGIN);
        if ($ver !== null && version_compare($ver, '2.0.0', '>=')) {
            return true;
        }
        // 版が取れない場合は、v2 の option が入っていれば v2 とみなす。
        $o = get_option('easy_wp_smtp', null);
        return is_array($o) && !empty($o);
    }

    /**
     * v2系（option 'easy_wp_smtp'）
     *
     * @return array
     */
    private function get_v2_data() {
        $o = get_option('easy_wp_smtp', array());
        if (!is_array($o)) $o = array();
        $mail = isset($o['mail']) && is_array($o['mail']) ? $o['mail'] : array();
        $smtp = isset($o['smtp']) && is_array($o['smtp']) ? $o['smtp'] : array();

        // 定数で上書きしている場合も「設定あり」とみなす。復号は行わない。
        $pass_set = $this->is_set($this->find_value($smtp, array('pass')))
            || defined('EASY_WP_SMTP_SMTP_PASS');

        return $this->build_response(array(
            'version'      => $this->get_plugin_version(self::PLUGIN),
            'mailer'       => $this->find_value($mail, array('mailer')),
            'host'         => $this->find_value($smtp, array('host')),
            'port'         => $this->find_value($smtp, array('port')),
            'encryption'   => $this->find_value($smtp, array('encryption')),
            'auth'         => (bool) $this->find_value($smtp, array('auth')),
            'username'     => $this->find_value($smtp, array('user')),
            'password_set' => $pass_set,
            'from_email'   => $this->find_value($mail, array('from_email')),
            'from_name'    => $this->find_value($mail, array('from_name')),
            'settings_url' => admin_url('admin.php?page=easy-wp-smtp'),
            'custom'       => array(
                'variant'           => 'v2',
                'autotls'           => (bool) $this->find_value($smtp, array('autotls')),
                'pass_via_constant' => defined('EASY_WP_SMTP_SMTP_PASS'),
            ),
        ));
    }

    /**
     * 旧系（option 'swpsmtp_options'・v1 系）。
     * ※ 点検済みフリートには現存しないが、移行途中などのため防御的に対応。
     *
     * @return array
     */
    private function get_legacy_data() {
        $o = get_option('swpsmtp_options', array());
        if (!is_array($o)) $o = array();
        $s = isset($o['smtp_settings']) && is_array($o['smtp_settings']) ? $o['smtp_settings'] : array();

        return $this->build_response(array(
            'version'      => $this->get_plugin_version(self::PLUGIN),
            'mailer'       => 'smtp',
            'host'         => $this->find_value($s, array('host')),
            'port'         => $this->find_value($s, array('port')),
            'encryption'   => $this->find_value($s, array('type_encryption')), // 'none'|'ssl'|'tls'
            'auth'         => ($this->find_value($s, array('autentication')) === 'yes'), // ※綴りtypoは実仕様
            'username'     => $this->find_value($s, array('username')),
            'password_set' => $this->is_set($this->find_value($s, array('password'))),
            'from_email'   => $this->find_value($o, array('from_email_field')),
            'from_name'    => $this->find_value($o, array('from_name_field')),
            'settings_url' => admin_url('admin.php?page=swpsmtp_settings'),
            'custom'       => array(
                'variant'        => 'legacy',
                'pass_encrypted' => (bool) get_option('swpsmtp_pass_encrypted', false),
            ),
        ));
    }
}
