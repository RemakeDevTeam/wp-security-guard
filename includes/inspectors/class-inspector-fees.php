<?php
/**
 * WP Security Guard - Fees Inspector (会費・キャンペーンバナー)
 *
 * 集中管理側（WPSM）の「会費・キャンペーン」ページ用。
 * 次の3つを、同梱(共通)トークンでも読める範囲に絞って返す。
 *
 *   plans    … 会費の設定（WP Full Pay のフォームのプラン＝Stripe の価格、BankPay の月額/年額/入会金 等）。
 *              金額・通貨・月/年・プラン名だけ。Stripe の価格ID・会員数・鍵などは返さない。
 *              ページに埋め込まれているフォームかどうか（used）も返す（古いフォームとの区別用）。
 *   pages    … 会員登録ページ（Ultimate Member の登録ページ）と特商法ページのURL。
 *   campaign … キャンペーンバナーのプラグイン（CampaignSP / CampaignQuo）の有効・無効と、
 *              設定「バナーの表示」（block＝表示 / none＝非表示）、プレゼント金額、
 *              ショートコードを置いているページ。
 *
 * いずれも各サイトの公開ページに表示している内容か、その設定値。読み取り専用。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Inspector_Fees extends WPSG_Inspector_Base {

    /** プランとして返す項目（これ以外は捨てる）。 */
    const PLAN_FIELDS = array(
        'implementation', 'product_type', 'form_type', 'form_slug', 'form_name', 'plan_name', 'name',
        'amount', 'currency', 'interval', 'interval_count', 'trial_days', 'setup_fee', 'entrance_fee',
        'tax_rate_percent', 'payment_method',
    );

    /** キャンペーンバナーのプラグイン。 */
    const CAMPAIGN_PLUGINS = array(
        'campaignsp' => array(
            'file'      => 'campaignsp/campaignsp.php',
            'option'    => 'campaignsp_settings',
            'shortcode' => 'campaignSP',
        ),
        'campaignquo' => array(
            'file'      => 'campaignquo-shortcode/campaignquo-shortcode.php',
            'option'    => 'campaignquo_settings',
            'shortcode' => 'campaignquo',
        ),
    );

    /**
     * @inheritDoc
     */
    public function inspect() {
        return array(
            'plans'    => $this->plans(),
            'pages'    => $this->pages(),
            'campaign' => $this->campaign(),
        );
    }

    // ------------------------------------------------------------------
    // 会費の設定
    // ------------------------------------------------------------------

    private function plans() {
        $out = array(
            'implementations' => array(),
            'items'           => array(),
            'forms_on_pages'  => null, // ページに埋め込まれている WP Full Pay のフォーム名（不明なら null）
        );
        if (!class_exists('WPSG_Membership_Dispatcher')) {
            return $out;
        }
        $dispatcher = new WPSG_Membership_Dispatcher();
        $result     = $dispatcher->inspect_all();
        foreach ($dispatcher->get_warnings() as $w) {
            if (isset($w['level']) && 'error' === $w['level']) {
                $this->warnings[] = $w;
            }
        }
        $out['implementations'] = isset($result['implementations']) ? array_values($result['implementations']) : array();

        $used = $this->wpfs_forms_on_pages();
        $out['forms_on_pages'] = $used;

        foreach ((array) (isset($result['products']) ? $result['products'] : array()) as $p) {
            if (!is_array($p) || !isset($p['amount']) || !is_numeric($p['amount'])) {
                continue; // 金額の無いもの（UM のロール等）は会費ではない。
            }
            $item = array();
            foreach (self::PLAN_FIELDS as $k) {
                if (array_key_exists($k, $p)) {
                    $item[$k] = $p[$k];
                }
            }
            $item['amount'] = (int) $p['amount'];
            if (isset($p['implementation']) && 'wp-full-stripe' === $p['implementation'] && is_array($used)) {
                $item['used'] = in_array((string) (isset($p['form_slug']) ? $p['form_slug'] : ''), $used, true);
            }
            $out['items'][] = $item;
        }
        return $out;
    }

    /**
     * 公開中のページ・投稿に置いてある [fullstripe_form name="…"] のフォーム名。
     * 1つも見つからなければ null（テーマ側で埋め込んでいる等で判断できない）。
     */
    private function wpfs_forms_on_pages() {
        $wpdb = $this->wpdb();
        $rows = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type NOT IN ('revision','nav_menu_item')
               AND post_content LIKE '%fullstripe_form%' LIMIT 50"
        );
        $names = array();
        foreach ((array) $rows as $c) {
            if (preg_match_all('/\[fullstripe_form[^\]]*\bname\s*=\s*["\']?([^"\'\s\]]+)/i', (string) $c, $m)) {
                foreach ($m[1] as $n) {
                    $names[$n] = true;
                }
            }
        }
        return $names ? array_keys($names) : null;
    }

    // ------------------------------------------------------------------
    // 会員登録ページ・特商法ページ
    // ------------------------------------------------------------------

    private function pages() {
        $register = '';
        // Ultimate Member の登録ページ（設定「core_register」）。
        $um = get_option('um_options');
        if (is_array($um) && !empty($um['core_register'])) {
            $pid = (int) $um['core_register'];
            if ($pid > 0 && 'publish' === get_post_status($pid)) {
                $register = (string) get_permalink($pid);
            }
        }
        if ('' === $register) {
            $p = get_page_by_path('register');
            if ($p && 'publish' === $p->post_status) {
                $register = (string) get_permalink($p);
            }
        }

        // 特商法ページ：スラッグ tokushoho か、タイトルに「特定商取引」「特商法」を含む公開ページ。
        $tokushoho = array();
        $p = get_page_by_path('tokushoho');
        if ($p && 'publish' === $p->post_status) {
            $tokushoho[] = (string) get_permalink($p);
        }
        $wpdb = $this->wpdb();
        $ids  = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'
               AND (post_title LIKE '%特定商取引%' OR post_title LIKE '%特商法%')
             ORDER BY menu_order, ID LIMIT 5"
        );
        foreach ((array) $ids as $id) {
            $u = (string) get_permalink((int) $id);
            if ('' !== $u && !in_array($u, $tokushoho, true)) {
                $tokushoho[] = $u;
            }
        }
        return array(
            'register'  => $register,
            'tokushoho' => $tokushoho,
        );
    }

    // ------------------------------------------------------------------
    // キャンペーンバナー
    // ------------------------------------------------------------------

    private function campaign() {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $out = array();
        foreach (self::CAMPAIGN_PLUGINS as $key => $def) {
            $installed = file_exists(WP_PLUGIN_DIR . '/' . $def['file']);
            $active    = $installed && (is_plugin_active($def['file']) || (is_multisite() && is_plugin_active_for_network($def['file'])));
            $row = array(
                'installed'   => $installed,
                'active'      => $active,
                'display'     => null,
                'gift_amount' => null,
                'used_on'     => array(),
            );
            if ($installed) {
                $s = get_option($def['option'], null);
                if (is_array($s)) {
                    // プラグインと同じ判定（block 以外は非表示）。設定を一度も保存していなければ null。
                    $row['display']     = (isset($s['display']) && 'block' === $s['display']) ? 'block' : 'none';
                    $row['gift_amount'] = isset($s['gift_amount']) ? (string) $s['gift_amount'] : null;
                }
                $row['used_on'] = $this->shortcode_pages($def['shortcode']);
            }
            $out[$key] = $row;
        }
        return $out;
    }

    /** ショートコードを置いている公開ページ・投稿のURL（最大5件）。 */
    private function shortcode_pages($tag) {
        $wpdb = $this->wpdb();
        $like = '%' . $wpdb->esc_like('[' . $tag) . '%';
        $ids  = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type NOT IN ('revision','nav_menu_item')
               AND post_content LIKE %s ORDER BY ID LIMIT 5",
            $like
        ));
        $urls = array();
        foreach ((array) $ids as $id) {
            $u = (string) get_permalink((int) $id);
            if ('' !== $u) {
                $urls[] = $u;
            }
        }
        return $urls;
    }
}
