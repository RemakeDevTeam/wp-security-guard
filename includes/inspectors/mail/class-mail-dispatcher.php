<?php
/**
 * WP Security Guard - Mail Dispatcher
 *
 * メール送信プラグイン別 inspector をまとめて実行し、結果を統合する。
 * Stripe dispatcher と対称。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.10.1
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Mail_Dispatcher {

    /** @var WPSG_Mail_Inspector_Base[] */
    private $inspectors = array();

    /** @var array */
    private $warnings = array();

    public function __construct() {
        $this->inspectors = array(
            new WPSG_Mail_Easy_WP_SMTP(),
            new WPSG_Mail_MailPoet(),
        );
        $this->inspectors = apply_filters('wpsg_inspect_mail_inspectors', $this->inspectors);
    }

    /**
     * 全インスペクター実行
     *
     * @return array
     */
    public function inspect_all() {
        $result = array(
            'implementations' => array(),
            'details'         => array(),
        );

        foreach ($this->inspectors as $inspector) {
            if (!($inspector instanceof WPSG_Mail_Inspector_Base)) continue;
            try {
                if ($inspector->is_active()) {
                    $impl_id = $inspector->get_implementation_id();
                    $result['implementations'][] = $impl_id;
                    $data = $inspector->get_data();
                    $result['details'][$impl_id] = is_array($data) ? $data : array();
                }
                // 警告はactive/inactiveに関わらず収集
                foreach ($inspector->get_warnings() as $w) {
                    $this->warnings[] = $w;
                }
            } catch (Exception $e) {
                $this->warnings[] = array(
                    'level'   => 'error',
                    'code'    => 'mail_inspector_exception',
                    'message' => sprintf('%s: %s', get_class($inspector), $e->getMessage()),
                );
            }
        }

        $result['summary'] = $this->build_summary($result['details']);
        $this->check_wp_mail_consistency($result['details'], $result['summary']);

        return $result;
    }

    public function get_warnings() {
        return $this->warnings;
    }

    /**
     * 「結局いまサイトの wp_mail を誰が握っているか」を最善推定する。
     *   - MailPoet の default_wp_mailer が false（='1'） → MailPoet
     *   - それ以外で Easy WP SMTP が有効かつ host 設定あり        → Easy WP SMTP
     *   - どちらでもない                                          → wp_default（WP標準/PHPMailer）
     * ※ 本モジュールは Easy WP SMTP / MailPoet の2種のみを見る。他のSMTP系
     *    （WP Mail SMTP・FluentSMTP 等）が送信を担っていても判定対象外。
     *
     * @param array $details
     * @return array
     */
    private function build_summary(array $details) {
        $mp_default_wp = null;   // MailPoet があるときのみ true/false
        if (isset($details['mailpoet']['custom']['default_wp_mailer'])) {
            $mp_default_wp = (bool) $details['mailpoet']['custom']['default_wp_mailer'];
        }

        $easy_configured = isset($details['easy-wp-smtp'])
            && $this->non_empty($details['easy-wp-smtp'], 'host');

        $controller = 'wp_default';
        if ($mp_default_wp === false) {
            $controller = 'mailpoet';
        } elseif ($easy_configured) {
            $controller = 'easy-wp-smtp';
        }

        return array(
            'implementations_count'   => count($details),
            'wp_mail_controller'      => $controller, // 'mailpoet'|'easy-wp-smtp'|'wp_default'
            'mailpoet_default_wp'     => $mp_default_wp,
            'easy_wp_smtp_configured' => $easy_configured,
        );
    }

    /**
     * 2つ以上がサイト全体の wp_mail を握り得るときに警告する（誤設定の可視化）。
     * 例: MailPoet の「すべてのメールを送信」がON（default_wp_mailer=false）かつ
     *     Easy WP SMTP も設定済み → phpmailer_init の主導権が衝突しうる。
     *
     * @param array $details
     * @param array $summary
     * @return void
     */
    private function check_wp_mail_consistency(array $details, array $summary) {
        if ($summary['mailpoet_default_wp'] === false && $summary['easy_wp_smtp_configured']) {
            $this->warnings[] = array(
                'level'   => 'warning',
                'code'    => 'mail_wp_mail_contention',
                'message' => 'MailPoet が全メールの送信を肩代わりする設定（高度な設定）になっており、同時に Easy WP SMTP も設定されています。wp_mail の主導権が競合する可能性があります。',
                'details' => array('wp_mail_controller' => $summary['wp_mail_controller']),
            );
        }
    }

    /**
     * $arr[$key] が非空か。
     *
     * @param array  $arr
     * @param string $key
     * @return bool
     */
    private function non_empty($arr, $key) {
        return is_array($arr) && isset($arr[$key]) && $arr[$key] !== '' && $arr[$key] !== null;
    }
}
