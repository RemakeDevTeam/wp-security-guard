<?php
/**
 * WP Security Guard - Stripe Inspector (top-level)
 *
 * REST API から呼ばれる Stripe inspectorのエントリーポイント。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Inspector_Stripe extends WPSG_Inspector_Base {

    public function inspect() {
        $dispatcher = new WPSG_Stripe_Dispatcher();
        $result = $dispatcher->inspect_all();

        foreach ($dispatcher->get_warnings() as $w) {
            $this->warnings[] = $w;
        }

        if (empty($result['implementations'])) {
            $this->add_warning(
                'notice',
                'no_payment_implementation_detected',
                '決済実装が検出されませんでした'
            );
        }

        return $result;
    }
}
