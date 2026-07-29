<?php
/**
 * WP Security Guard - Theme Inspector
 *
 * 有効テーマ・親テーマの情報、利用可能な更新を取得する。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Inspector_Theme extends WPSG_Inspector_Base {

    /**
     * @inheritDoc
     */
    public function inspect() {
        $current_theme = wp_get_theme();
        $data = array(
            'active' => $this->theme_to_array($current_theme),
        );

        // 親テーマ(子テーマの場合)
        $parent = $current_theme->parent();
        if ($parent && $parent->exists()) {
            $data['parent'] = $this->theme_to_array($parent);
            $data['is_child_theme'] = true;
        } else {
            $data['parent'] = null;
            $data['is_child_theme'] = false;
        }

        // 利用可能な更新の確認
        $updates = $this->get_theme_updates();
        $current_stylesheet = $current_theme->get_stylesheet();
        if (isset($updates[$current_stylesheet])) {
            $data['active']['update_available'] = true;
            $data['active']['new_version'] = isset($updates[$current_stylesheet]['new_version'])
                ? $updates[$current_stylesheet]['new_version']
                : null;
        } else {
            $data['active']['update_available'] = false;
        }

        // 親テーマの更新も確認
        if ($data['is_child_theme'] && $parent) {
            $parent_stylesheet = $parent->get_stylesheet();
            if (isset($updates[$parent_stylesheet])) {
                $data['parent']['update_available'] = true;
                $data['parent']['new_version'] = isset($updates[$parent_stylesheet]['new_version'])
                    ? $updates[$parent_stylesheet]['new_version']
                    : null;
            } else {
                $data['parent']['update_available'] = false;
            }
        }

        return $data;
    }

    /**
     * WP_Theme オブジェクトを配列に変換
     *
     * @param WP_Theme $theme
     * @return array
     */
    private function theme_to_array($theme) {
        return array(
            'name'        => (string) $theme->get('Name'),
            'version'     => (string) $theme->get('Version'),
            'stylesheet'  => (string) $theme->get_stylesheet(),
            'template'    => (string) $theme->get_template(),
            'author'      => (string) $theme->get('Author'),
            'theme_uri'   => (string) $theme->get('ThemeURI'),
            'description' => wp_strip_all_tags((string) $theme->get('Description')),
            'requires_wp' => (string) $theme->get('RequiresWP'),
            'requires_php'=> (string) $theme->get('RequiresPHP'),
            'exists'      => (bool) $theme->exists(),
        );
    }

    /**
     * テーマ更新情報を取得
     *
     * @return array key=stylesheet, value=update info
     */
    private function get_theme_updates() {
        $updates = get_site_transient('update_themes');
        if (is_object($updates) && !empty($updates->response) && is_array($updates->response)) {
            return $updates->response;
        }
        return array();
    }
}
