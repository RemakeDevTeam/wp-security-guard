<?php
/**
 * WP Security Guard - Membership Inspector (top-level)
 *
 * REST APIから呼ばれるトップレベルinspector。
 * 内部でディスパッチャーを使い、6種類の会員管理inspectorを統合する。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Inspector_Membership extends WPSG_Inspector_Base {

    /**
     * @inheritDoc
     */
    public function inspect() {
        $dispatcher = new WPSG_Membership_Dispatcher();
        $result = $dispatcher->inspect_all();

        // 各サブインスペクターからの警告を統合
        foreach ($dispatcher->get_warnings() as $warning) {
            // get_warnings() は base classが [level, code, message, details] 形式で返す
            $this->warnings[] = $warning;
        }

        // 実装が1つも検出できなかった場合の通知 (致命的ではないが情報として)
        if (empty($result['implementations'])) {
            $this->add_warning(
                'notice',
                'no_membership_implementation_detected',
                '会員管理プラグインが検出されませんでした。サイトにUM/WP Full Stripe/WC/BankPay等が無いか、機能が無効化されている可能性があります'
            );
        }

        return $result;
    }
}
