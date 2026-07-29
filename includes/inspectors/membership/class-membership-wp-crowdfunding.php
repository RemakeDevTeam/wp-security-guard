<?php
/**
 * WP Security Guard - WP Crowdfunding Membership Inspector
 *
 * WP Crowdfundingのプロジェクト情報を返す。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Membership_WP_Crowdfunding extends WPSG_Membership_Inspector_Base {

    /** プロジェクト取得時の上限 */
    const PROJECTS_LIMIT = 200;

    /**
     * @inheritDoc
     */
    public function is_active() {
        return class_exists('WP_Crowdfunding')
            || class_exists('WPCF\\Crowdfunding')
            || $this->is_plugin_active('wp-crowdfunding/wp-crowdfunding.php');
    }

    /**
     * @inheritDoc
     */
    public function get_implementation_id() {
        return 'wp-crowdfunding';
    }

    /**
     * @inheritDoc
     */
    public function get_products() {
        if (!$this->is_active()) {
            return array();
        }
        if (!function_exists('wc_get_products')) {
            return array();
        }

        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'JPY';

        // WP Crowdfundingは内部でWooCommerce商品を 'crowdfunding' タイプとして扱う
        // ただし、サイトによっては独自の post_type を使う場合もあるので両方試す
        $products = array();

        try {
            $cf_products = wc_get_products(array(
                'type'   => 'crowdfunding',
                'status' => 'publish',
                'limit'  => self::PROJECTS_LIMIT,
            ));
        } catch (Exception $e) {
            $cf_products = array();
            $this->add_warning(
                'notice',
                'wp_crowdfunding_query_failed',
                'WP Crowdfunding商品の取得に失敗しました: ' . $e->getMessage()
            );
        }

        foreach ((array) $cf_products as $project) {
            if (!is_object($project)) continue;
            $products[] = array(
                'id'              => 'wp-crowdfunding:' . $project->get_id(),
                'implementation'  => 'wp-crowdfunding',
                'product_type'    => 'crowdfunding_project',
                'name'            => $project->get_name(),
                'product_id'      => $project->get_id(),
                'goal_amount'     => (int) $project->get_meta('wpcf_goal'),
                'raised_amount'   => (int) $project->get_meta('wpcf_funded_amount'),
                'min_amount'      => (int) $project->get_meta('wpcf_min_amount'),
                'max_amount'      => $project->get_meta('wpcf_max_amount') !== '' ? (int) $project->get_meta('wpcf_max_amount') : null,
                'amount'          => (int) $project->get_meta('wpcf_funded_amount'),
                'currency'        => $currency,
                'start_date'      => $project->get_meta('wpcf_start'),
                'end_date'        => $project->get_meta('wpcf_end'),
                'project_status'  => $project->get_meta('wpcf_status'),
                'active'          => $project->get_status() === 'publish',
                'rewards'         => $this->get_reward_summary($project->get_id()),
            );
        }

        return $products;
    }

    /**
     * @inheritDoc
     */
    public function get_metadata() {
        return array(
            'plugin_version' => $this->get_plugin_version('wp-crowdfunding/wp-crowdfunding.php'),
            'project_count'  => $this->get_project_count(),
            'has_woocommerce'=> class_exists('WooCommerce'),
        );
    }

    // ========================================================================
    // ヘルパー
    // ========================================================================

    /**
     * リワード情報のサマリー
     *
     * @param int $project_id
     * @return array
     */
    private function get_reward_summary($project_id) {
        $rewards = get_post_meta($project_id, 'wpneo_reward_data', true);
        if (!is_array($rewards) || empty($rewards)) {
            return array('count' => 0, 'amounts' => array());
        }
        $amounts = array();
        foreach ($rewards as $reward) {
            if (is_array($reward) && isset($reward['amount'])) {
                $amounts[] = (int) $reward['amount'];
            }
        }
        sort($amounts);
        return array(
            'count'   => count($rewards),
            'amounts' => $amounts,
        );
    }

    /**
     * 公開ステータスのプロジェクト総数
     */
    private function get_project_count() {
        $wpdb = $this->wpdb();
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE p.post_type = %s 
             AND p.post_status = %s
             AND tt.taxonomy = %s
             AND t.slug = %s",
            'product', 'publish', 'product_type', 'crowdfunding'
        ));
        return $count;
    }
}
