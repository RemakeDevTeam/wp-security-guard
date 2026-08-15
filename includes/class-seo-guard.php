<?php
/**
 * WP Security Guard - SEO Guard 適用・復元
 *
 * 検索対策(noindex付与・メタタグ変更)を実際に書き込む。
 *
 * 設計方針:
 *   - dry_run 既定 ON。false の明示指定が無ければ絶対に書き込まない。
 *   - 変更前の値の保存に失敗した操作は実行しない(必ず戻せる状態を保つ)。
 *   - 受け付ける操作はホワイトリストのみ。任意のメタ更新は受け付けない。
 *   - すべての適用はバッチIDで束ね、バッチ単位で復元できる。
 *
 * @package WPSecurityGuard
 * @subpackage SeoGuard
 * @since 2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_SEO_Guard {

    /** @var string WPSG独自のnoindex対象(投稿ID配列)。SEOプラグイン非導入サイト向け。 */
    const OPT_NOINDEX = 'wpsg_seo_noindex_ids';

    /** @var string 変更前の値のバックアップ(バッチID => 操作配列)。 */
    const OPT_BACKUP = 'wpsg_seo_backup';

    /** @var string 監査ログ(直近100件)。 */
    const OPT_LOG = 'wpsg_seo_log';

    /** @var int 1リクエストで受け付ける操作数の上限。 */
    const MAX_OPS = 50;

    /** @var int 保持するバックアップのバッチ数。 */
    const MAX_BATCHES = 50;

    /**
     * フック登録。
     */
    public static function init() {
        // WPSG独自のnoindex(SEOプラグインが無い/使わない場合の受け皿)。
        add_filter('wp_robots', array(__CLASS__, 'filter_robots'), 99);
    }

    /**
     * WPSG が noindex 指定した投稿に robots を付与する。
     *
     * @param array $robots
     * @return array
     */
    public static function filter_robots($robots) {
        if (!is_singular()) {
            return $robots;
        }
        $ids = array_map('intval', (array) get_option(self::OPT_NOINDEX, array()));
        if (empty($ids)) {
            return $robots;
        }
        $id = (int) get_queried_object_id();
        if ($id > 0 && in_array($id, $ids, true)) {
            $robots['noindex']  = true;
            $robots['nofollow'] = false;
            unset($robots['index']);
        }
        return $robots;
    }

    /**
     * 操作群を適用する(または dry_run で差分だけ返す)。
     *
     * @param array $ops     操作の配列。
     * @param bool  $dry_run true なら書き込まない。
     * @return array|WP_Error
     */
    public static function apply($ops, $dry_run = true) {
        if (!is_array($ops) || empty($ops)) {
            return new WP_Error('no_ops', 'No operations supplied.', array('status' => 400));
        }
        if (count($ops) > self::MAX_OPS) {
            return new WP_Error(
                'too_many_ops',
                sprintf('Too many operations (max %d).', self::MAX_OPS),
                array('status' => 400)
            );
        }

        $seo_plugin = WPSG_Inspector_Seo::detect_seo_plugin();
        $batch_id   = 'b' . gmdate('YmdHis') . '-' . wp_generate_password(6, false, false);
        $results    = array();
        $backup     = array();

        foreach ($ops as $i => $op) {
            $res = self::plan_one($op, $seo_plugin);
            if (is_wp_error($res)) {
                $results[] = array(
                    'index'  => $i,
                    'status' => 'error',
                    'code'   => $res->get_error_code(),
                    'message'=> $res->get_error_message(),
                );
                continue;
            }

            // 変更がない場合は書き込まない。
            if ($res['before'] === $res['after']) {
                $results[] = array(
                    'index'  => $i,
                    'op'     => $res['op'],
                    'target' => $res['target'],
                    'status' => 'unchanged',
                    'before' => $res['before'],
                    'after'  => $res['after'],
                );
                continue;
            }

            if ($dry_run) {
                $results[] = array(
                    'index'  => $i,
                    'op'     => $res['op'],
                    'target' => $res['target'],
                    'status' => 'would_change',
                    'before' => $res['before'],
                    'after'  => $res['after'],
                );
                continue;
            }

            // 本適用。バックアップを先に積み、書き込みに失敗したら記録しない。
            $written = self::write_one($res, $seo_plugin);
            if (is_wp_error($written)) {
                $results[] = array(
                    'index'  => $i,
                    'op'     => $res['op'],
                    'target' => $res['target'],
                    'status' => 'error',
                    'code'   => $written->get_error_code(),
                    'message'=> $written->get_error_message(),
                );
                continue;
            }

            $backup[] = array(
                'op'     => $res['op'],
                'target' => $res['target'],
                'before' => $res['before'],
            );
            $results[] = array(
                'index'  => $i,
                'op'     => $res['op'],
                'target' => $res['target'],
                'status' => 'changed',
                'before' => $res['before'],
                'after'  => $res['after'],
            );
        }

        if (!$dry_run && !empty($backup)) {
            self::store_backup($batch_id, $backup);
            self::log($batch_id, count($backup));
        }

        return array(
            'dry_run'    => (bool) $dry_run,
            'batch_id'   => $dry_run ? null : $batch_id,
            'seo_plugin' => $seo_plugin,
            'results'    => $results,
            'summary'    => self::summarize($results),
        );
    }

    /**
     * 単一操作を検証し、変更前後の値を算出する(書き込みはしない)。
     *
     * @param array  $op
     * @param string $seo_plugin
     * @return array|WP_Error
     */
    private static function plan_one($op, $seo_plugin) {
        if (!is_array($op) || empty($op['op'])) {
            return new WP_Error('op_invalid', 'Operation must have an "op" key.');
        }
        $name = (string) $op['op'];

        switch ($name) {
            case 'page_noindex':
                $post_id = isset($op['post_id']) ? (int) $op['post_id'] : 0;
                if ($post_id <= 0) {
                    return new WP_Error('op_invalid', 'page_noindex requires post_id.');
                }
                $post = get_post($post_id);
                if (!$post) {
                    return new WP_Error('not_found', 'Post not found: ' . $post_id);
                }
                // 対象は page / post のみ。他の投稿タイプには触れない。
                if (!in_array($post->post_type, array('page', 'post'), true)) {
                    return new WP_Error('type_not_allowed', 'Only page/post are allowed: ' . $post->post_type);
                }
                $value = !empty($op['value']);
                return array(
                    'op'     => $name,
                    'target' => array('post_id' => $post_id, 'title' => (string) $post->post_title),
                    'before' => WPSG_Inspector_Seo::is_noindex($post_id, $seo_plugin) ? '1' : '0',
                    'after'  => $value ? '1' : '0',
                    'value'  => $value,
                );

            case 'meta_title':
            case 'meta_desc':
                $scope = isset($op['scope']) ? (string) $op['scope'] : 'front';
                if ($scope !== 'front') {
                    return new WP_Error('scope_not_allowed', 'Only scope=front is supported.');
                }
                if (!isset($op['value']) || !is_string($op['value'])) {
                    return new WP_Error('op_invalid', $name . ' requires a string value.');
                }
                if ($seo_plugin !== 'yoast') {
                    return new WP_Error('plugin_unsupported', 'Meta rewriting currently supports Yoast SEO only (detected: ' . $seo_plugin . ').');
                }
                $value  = trim($op['value']);
                if (mb_strlen($value) > 320) {
                    return new WP_Error('value_too_long', 'Value is too long.');
                }
                $before = self::read_front_meta($name);
                return array(
                    'op'     => $name,
                    'target' => array('scope' => 'front'),
                    'before' => $before,
                    'after'  => $value,
                    'value'  => $value,
                );
        }

        return new WP_Error('op_unknown', 'Unknown operation: ' . $name);
    }

    /**
     * 実際に書き込む。
     *
     * @param array  $plan
     * @param string $seo_plugin
     * @return true|WP_Error
     */
    private static function write_one($plan, $seo_plugin) {
        switch ($plan['op']) {
            case 'page_noindex':
                $post_id = (int) $plan['target']['post_id'];
                $value   = (bool) $plan['value'];

                if ($seo_plugin === 'yoast') {
                    // '1' = noindex / '2' = index(既定に頼らず明示する)
                    $ok = update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', $value ? '1' : '2');
                    if ($ok === false) {
                        return new WP_Error('write_failed', 'Failed to update Yoast meta for ' . $post_id);
                    }
                } else {
                    // SEOプラグインが無い場合は WPSG 独自の一覧で管理する。
                    $ids = array_map('intval', (array) get_option(self::OPT_NOINDEX, array()));
                    $ids = array_values(array_diff($ids, array($post_id)));
                    if ($value) {
                        $ids[] = $post_id;
                    }
                    update_option(self::OPT_NOINDEX, array_values(array_unique($ids)), false);
                }
                return true;

            case 'meta_title':
            case 'meta_desc':
                return self::write_front_meta($plan['op'], (string) $plan['value']);
        }
        return new WP_Error('op_unknown', 'Unknown operation.');
    }

    /**
     * トップページの title / description を読む(Yoast)。
     *
     * @param string $op meta_title|meta_desc
     * @return string
     */
    private static function read_front_meta($op) {
        $front_id = (int) get_option('page_on_front');
        $meta_key = ($op === 'meta_title') ? '_yoast_wpseo_title' : '_yoast_wpseo_metadesc';
        $opt_key  = ($op === 'meta_title') ? 'title-home-wpseo' : 'metadesc-home-wpseo';

        if ($front_id > 0) {
            $v = (string) get_post_meta($front_id, $meta_key, true);
            if ($v !== '') {
                return $v;
            }
        }
        $titles = (array) get_option('wpseo_titles', array());
        return isset($titles[$opt_key]) ? (string) $titles[$opt_key] : '';
    }

    /**
     * トップページの title / description を書く(Yoast)。
     *
     * 静的フロントページがあるサイトはページ個別のメタが優先されるため、
     * そちらに書く。無い場合は wpseo_titles オプションを書き換える。
     *
     * @param string $op
     * @param string $value
     * @return true|WP_Error
     */
    private static function write_front_meta($op, $value) {
        $front_id = (int) get_option('page_on_front');
        $meta_key = ($op === 'meta_title') ? '_yoast_wpseo_title' : '_yoast_wpseo_metadesc';
        $opt_key  = ($op === 'meta_title') ? 'title-home-wpseo' : 'metadesc-home-wpseo';

        if ($front_id > 0) {
            if (update_post_meta($front_id, $meta_key, $value) === false) {
                return new WP_Error('write_failed', 'Failed to update front page meta.');
            }
            return true;
        }

        $titles = (array) get_option('wpseo_titles', array());
        $titles[$opt_key] = $value;
        update_option('wpseo_titles', $titles);
        return true;
    }

    /**
     * バックアップを保存する。
     *
     * @param string $batch_id
     * @param array  $ops
     */
    private static function store_backup($batch_id, $ops) {
        $all = (array) get_option(self::OPT_BACKUP, array());
        $all[$batch_id] = array(
            'at'  => current_time('mysql'),
            'ops' => $ops,
        );
        if (count($all) > self::MAX_BATCHES) {
            $all = array_slice($all, -self::MAX_BATCHES, null, true);
        }
        update_option(self::OPT_BACKUP, $all, false);
    }

    /**
     * バッチを復元する。
     *
     * @param string $batch_id
     * @return array|WP_Error
     */
    public static function rollback($batch_id) {
        $all = (array) get_option(self::OPT_BACKUP, array());
        if (!isset($all[$batch_id])) {
            return new WP_Error('batch_not_found', 'Batch not found: ' . $batch_id, array('status' => 404));
        }
        $seo_plugin = WPSG_Inspector_Seo::detect_seo_plugin();
        $restored   = array();

        foreach ((array) $all[$batch_id]['ops'] as $op) {
            $name = (string) $op['op'];
            if ($name === 'page_noindex') {
                $post_id = (int) $op['target']['post_id'];
                $plan = array(
                    'op'     => $name,
                    'target' => array('post_id' => $post_id),
                    'value'  => ($op['before'] === '1'),
                );
                $r = self::write_one($plan, $seo_plugin);
            } else {
                $plan = array(
                    'op'    => $name,
                    'value' => (string) $op['before'],
                );
                $r = self::write_one($plan, $seo_plugin);
            }
            $restored[] = array(
                'op'     => $name,
                'target' => isset($op['target']) ? $op['target'] : null,
                'status' => is_wp_error($r) ? 'error' : 'restored',
            );
        }

        unset($all[$batch_id]);
        update_option(self::OPT_BACKUP, $all, false);

        return array('batch_id' => $batch_id, 'restored' => $restored);
    }

    /**
     * 保存済みバッチの一覧。
     *
     * @return array
     */
    public static function batches() {
        $all = (array) get_option(self::OPT_BACKUP, array());
        $out = array();
        foreach ($all as $id => $b) {
            $out[] = array(
                'batch_id' => $id,
                'at'       => isset($b['at']) ? $b['at'] : '',
                'count'    => isset($b['ops']) ? count((array) $b['ops']) : 0,
            );
        }
        return $out;
    }

    /**
     * 監査ログに追記する。
     *
     * @param string $batch_id
     * @param int    $count
     */
    private static function log($batch_id, $count) {
        $log = (array) get_option(self::OPT_LOG, array());
        $log[] = array(
            'at'       => current_time('mysql'),
            'batch_id' => $batch_id,
            'count'    => (int) $count,
            'ip'       => WPSG_Inspect_Token::get_client_ip(),
        );
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }
        update_option(self::OPT_LOG, $log, false);
    }

    /**
     * 結果の集計。
     *
     * @param array $results
     * @return array
     */
    private static function summarize($results) {
        $s = array('changed' => 0, 'would_change' => 0, 'unchanged' => 0, 'error' => 0);
        foreach ($results as $r) {
            $k = isset($r['status']) ? $r['status'] : 'error';
            if (isset($s[$k])) {
                $s[$k]++;
            }
        }
        return $s;
    }
}
