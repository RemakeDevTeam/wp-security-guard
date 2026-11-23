<?php
/**
 * WP Security Guard - Mail Inspector (top-level)
 *
 * REST API から呼ばれるメール送信設定 inspector のエントリーポイント。
 * data.mail として /inspect/all に載る。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.10.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Inspector_Mail extends WPSG_Inspector_Base {

    public function inspect() {
        $dispatcher = new WPSG_Mail_Dispatcher();
        $result = $dispatcher->inspect_all();

        foreach ($dispatcher->get_warnings() as $w) {
            $this->warnings[] = $w;
        }

        // 対象2種（Easy WP SMTP / MailPoet）が検出されなくても、WP標準の
        // mail()（PHPMailer直）がベースライン。「検出なし＝SMTP未設定」ではない。
        $result['baseline_mailer'] = 'wp_default';

        if (empty($result['implementations'])) {
            $this->add_warning(
                'notice',
                'no_mail_plugin_detected',
                'Easy WP SMTP / MailPoet は検出されませんでした（他のSMTP系が送信を担っている可能性があります）'
            );
        }

        return $result;
    }
}
