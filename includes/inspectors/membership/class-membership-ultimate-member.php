<?php
/**
 * WP Security Guard - Ultimate Member Inspector
 *
 * Ultimate Member (UM) のロール情報を返す。
 * UMは金額管理機能を持たないため、製品(products)としては「ロール」を返す。
 * 金額情報は WP Full Stripe / BankPay 側で取得される。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Membership_Ultimate_Member extends WPSG_Membership_Inspector_Base {

    /** WP標準ロール一覧(これらは「UM管理ロール」とは区別する) */
    const STANDARD_WP_ROLES = array(
        'administrator', 'editor', 'author', 'contributor', 'subscriber',
    );

    /**
     * @inheritDoc
     */
    public function is_active() {
        return class_exists('UM')
            || function_exists('UM')
            || $this->is_plugin_active('ultimate-member/index.php');
    }

    /**
     * @inheritDoc
     */
    public function get_implementation_id() {
        return 'ultimate-member';
    }

    /**
     * @inheritDoc
     */
    public function get_products() {
        if (!$this->is_active()) {
            return array();
        }

        $um_managed_keys = $this->get_um_managed_role_keys();
        $all_roles = $this->get_all_roles();
        $products = array();

        foreach ($all_roles as $role_key => $role_data) {
            $is_um_managed = in_array($role_key, $um_managed_keys, true);
            $is_standard   = in_array($role_key, self::STANDARD_WP_ROLES, true);
            $user_count    = $this->count_users_with_role($role_key);

            $products[] = array(
                'id'              => 'ultimate-member:role:' . $role_key,
                'implementation'  => 'ultimate-member',
                'product_type'    => 'role',
                'name'            => isset($role_data['name']) ? (string) $role_data['name'] : $role_key,
                'role_key'        => $role_key,
                'um_managed'      => $is_um_managed,
                'standard_role'   => $is_standard,
                'user_count'      => $user_count,
                'amount'          => null,
                'currency'        => null,
                'note'            => '金額はWP Full Stripe / BankPay 側で管理',
            );
        }
        return $products;
    }

    /**
     * @inheritDoc
     */
    public function get_metadata() {
        $version = $this->get_plugin_version('ultimate-member/index.php');
        $um_managed_keys = $this->get_um_managed_role_keys();
        $all_roles = $this->get_all_roles();

        return array(
            'plugin_version'      => $version,
            'roles_count_total'   => count($all_roles),
            'roles_count_um'      => count($um_managed_keys),
            'roles_count_custom'  => count(array_diff(array_keys($all_roles), self::STANDARD_WP_ROLES)),
            'um_extensions'       => $this->detect_um_extensions(),
            'profile_menu_tabs'   => $this->get_profile_menu_tabs(),
        );
    }

    /**
     * UM プロフィールメニュータブの設定を取得
     *
     * UM の管理画面 (Ultimate Member > 設定 > 表示 > プロフィールメニュー) で
     * 各タブを有効化しているか + 表示ラベル + 表示順序を判定する。
     *
     * v2.4.5 で取得情報を強化:
     *   - tabs_with_labels: UM のフィルター経由で全タブを取得 (key, label, enabled, custom 含む)
     *   - menu_order:       表示順序の配列 (UM管理画面で並べ替えた結果)
     *   - raw_keys:         wp_options の生キー (デバッグ用)
     *
     * @return array
     */
    public function get_profile_menu_tabs() {
        $um_options = get_option('um_options', array());
        if (!is_array($um_options)) {
            return array(
                'available'        => false,
                'note'             => 'um_options が取得できません',
                'raw_keys'         => array(),
                'tabs'             => array(),
                'enabled_tabs'     => array(),
                'tabs_with_labels' => array(),
                'menu_order'       => array(),
                'menu_order_raw'   => null,
            );
        }

        // ----- raw_keys: profile_tab_* / profile_menu* で始まる全キー (デバッグ用) -----
        $raw_keys = array();
        foreach ($um_options as $key => $value) {
            if (strpos($key, 'profile_tab_') === 0
                || strpos($key, 'profile_menu') === 0) {
                $raw_keys[$key] = $value;
            }
        }

        // ----- tabs: 主要タブの有効/無効を抽出 (旧版互換用) -----
        $tab_keys_known = array(
            'main', 'posts', 'comments', 'photos', 'photo',
            'forums', 'forum',
            'messages', 'message',
            'notes', 'note',
            'notices', 'notice',
            'groups', 'groups_list',
            'reviews', 'review',
            'followers', 'follower',
            'following',
            'friends', 'friend',
            'activity',
        );
        $tabs = array();
        $enabled_tabs = array();
        foreach ($tab_keys_known as $tab) {
            $opt_key = 'profile_tab_' . $tab;
            if (array_key_exists($opt_key, $um_options)) {
                $is_enabled = !empty($um_options[$opt_key]);
                $tabs[$tab] = $is_enabled;
                if ($is_enabled) {
                    $enabled_tabs[] = $tab;
                }
            }
        }

        // ----- tabs_with_labels: UM のフィルター経由で全タブを取得 (ラベル付き) -----
        // UM の標準フィルター 'um_profile_tabs' を使うことで、カスタムタブも含む全タブを取得できる。
        // 戻り値は各タブが {name, icon, custom, default_privacy, ...} の形式。
        $tabs_with_labels = $this->get_tabs_via_um_filter($um_options);

        // ----- menu_order: 表示順序 -----
        // UM 管理画面で並べ替えた結果は profile_menu_order に格納される (タブキーの配列)
        $menu_order_raw = isset($um_options['profile_menu_order']) ? $um_options['profile_menu_order'] : null;
        $menu_order = array();
        if (is_array($menu_order_raw)) {
            $menu_order = array_values(array_map('strval', $menu_order_raw));
        } elseif (is_string($menu_order_raw)) {
            $decoded = @json_decode($menu_order_raw, true);
            if (is_array($decoded)) {
                $menu_order = array_values(array_map('strval', $decoded));
            }
        }

        return array(
            'available'         => true,
            'raw_keys'          => $raw_keys,
            'tabs'              => $tabs,
            'enabled_tabs'      => $enabled_tabs,
            'tabs_with_labels'  => $tabs_with_labels,
            'menu_order'        => $menu_order,
            'menu_order_raw'    => $menu_order_raw,
        );
    }

    /**
     * UM の um_profile_tabs フィルター経由で全タブを取得
     *
     * UM 本体のフィルターを呼ぶと、以下の構造で各タブが返る:
     *   array(
     *       'main'    => array('name' => 'About', 'icon' => 'um-faicon-...', 'default_privacy' => 0),
     *       'forums'  => array('name' => 'Forums', ...),
     *       '138'     => array('name' => '案件', 'custom' => true, ...),
     *       ...
     *   )
     *
     * これに、wp_options の profile_tab_<key> 値 (有効/無効) と表示順を結合する。
     *
     * @param array $um_options
     * @return array
     */
    private function get_tabs_via_um_filter($um_options) {
        // UM 本体が読み込まれていない場合は空配列を返す
        if (!function_exists('apply_filters')) {
            return array();
        }
        // UM 本体が初期化されていない場合は空配列を返す
        if (!class_exists('UM') && !function_exists('UM')) {
            return array();
        }

        // フィルターを呼び出して全タブを取得
        // 第2引数の null はユーザーコンテキスト (ログイン中ユーザー前提でない呼び出し)
        $all_tabs = array();
        try {
            // UM 2.x 系では空配列を渡すと、UM 本体と各 Extension がそれぞれタブを add してくれる
            $all_tabs = apply_filters('um_profile_tabs', array());
        } catch (\Exception $e) {
            // 念のため例外捕捉
            return array();
        }

        if (!is_array($all_tabs)) {
            return array();
        }

        // 表示順序 (profile_menu_order) があれば、そのソート用に index 化
        $menu_order_raw = isset($um_options['profile_menu_order']) ? $um_options['profile_menu_order'] : array();
        if (is_string($menu_order_raw)) {
            $decoded = @json_decode($menu_order_raw, true);
            $menu_order_raw = is_array($decoded) ? $decoded : array();
        }
        if (!is_array($menu_order_raw)) {
            $menu_order_raw = array();
        }
        $order_index = array();
        $idx = 0;
        foreach ($menu_order_raw as $k) {
            $order_index[(string) $k] = $idx;
            $idx++;
        }

        $result = array();
        foreach ($all_tabs as $tab_key => $tab_data) {
            $tab_key_str = (string) $tab_key;
            $opt_key = 'profile_tab_' . $tab_key_str;
            $is_enabled = isset($um_options[$opt_key]) ? !empty($um_options[$opt_key]) : false;

            $label = '';
            $is_custom = false;
            $icon = '';
            if (is_array($tab_data)) {
                $label = isset($tab_data['name']) ? (string) $tab_data['name'] : '';
                $is_custom = !empty($tab_data['custom']);
                $icon = isset($tab_data['icon']) ? (string) $tab_data['icon'] : '';
            }

            $order = isset($order_index[$tab_key_str]) ? $order_index[$tab_key_str] : -1;

            $result[] = array(
                'key'       => $tab_key_str,
                'label'     => $label,
                'enabled'   => $is_enabled,
                'is_custom' => $is_custom,
                'icon'      => $icon,
                'order'     => $order,
            );
        }

        // order でソート (-1 は末尾に)
        usort($result, function ($a, $b) {
            $oa = $a['order'] >= 0 ? $a['order'] : PHP_INT_MAX;
            $ob = $b['order'] >= 0 ? $b['order'] : PHP_INT_MAX;
            if ($oa === $ob) return 0;
            return ($oa < $ob) ? -1 : 1;
        });

        return $result;
    }

    // ========================================================================
    // ヘルパー
    // ========================================================================

    /**
     * 全WPロールを取得
     *
     * @return array key=role_id, value=role_data
     */
    private function get_all_roles() {
        if (!function_exists('wp_roles')) {
            return array();
        }
        $wp_roles = wp_roles();
        return isset($wp_roles->roles) && is_array($wp_roles->roles) ? $wp_roles->roles : array();
    }

    /**
     * UMが管理しているロールのキー一覧を取得
     * um_role 投稿タイプの全レコードから取得
     *
     * @return array
     */
    private function get_um_managed_role_keys() {
        $keys = array();
        $posts = get_posts(array(
            'post_type'      => 'um_role',
            'numberposts'    => -1,
            'post_status'    => 'publish',
            'fields'         => 'all',
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ));
        if (!is_array($posts)) return $keys;

        foreach ($posts as $post) {
            // UMのロールキーは通常 'um_' プレフィックス付きと無印の両方が存在しうる
            if (!empty($post->post_name)) {
                $keys[] = $post->post_name;
                $keys[] = 'um_' . $post->post_name;
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * 指定ロールを持つユーザー数
     *
     * @param string $role_key
     * @return int
     */
    private function count_users_with_role($role_key) {
        if (!function_exists('count_users')) {
            return 0;
        }
        $counts = count_users();
        if (isset($counts['avail_roles'][$role_key])) {
            return (int) $counts['avail_roles'][$role_key];
        }
        return 0;
    }

    /**
     * UM 関連プラグイン(アドオン)の検出
     *
     * @return array
     */
    private function detect_um_extensions() {
        $candidates = array(
            'um-friends/um-friends.php'                     => 'UM Friends',
            'um-online/um-online.php'                       => 'UM Online',
            'um-private-content/um-private-content.php'     => 'UM Private Content',
            'um-followers/um-followers.php'                 => 'UM Followers',
            'um-notices/um-notices.php'                     => 'UM Notices',
            'um-notifications/um-notifications.php'         => 'UM Notifications',
            'um-mailchimp/um-mailchimp.php'                 => 'UM MailChimp',
            'um-real-time-notifications/um-real-time-notifications.php' => 'UM Real-time Notifications',
            'um-member-directory/um-member-directory.php'   => 'UM Member Directory',
            'um-social-login/um-social-login.php'           => 'UM Social Login',
        );
        $found = array();
        foreach ($candidates as $file => $name) {
            if ($this->is_plugin_active($file)) {
                $found[] = array(
                    'name'    => $name,
                    'file'    => $file,
                    'version' => $this->get_plugin_version($file),
                );
            }
        }
        return $found;
    }
}
