<?php
/**
 * WP Security Guard - SEO 点検 inspector
 *
 * 「会社名・屋号・代表者名で検索してもシステムサイトが出てこない状態」を作るための
 * 現状把握を行う。読み取り専用で、この段階では一切変更しない。
 *
 * 集中管理側(WP Site Manager)は外形(HTML取得)でも似た判定をしているが、
 * こちらは DB を直接読むため正確で、かつ「どのページのどのフィールドに
 * 名前が入っているか」まで特定できる。
 *
 * @package WPSecurityGuard
 * @subpackage SeoGuard
 * @since 2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Inspector_Seo extends WPSG_Inspector_Base {

    /**
     * 対象ページ種別ごとの判定材料。
     * slug は前方一致・完全一致の両方で見る。title は部分一致。
     *
     * @var array
     */
    const PAGE_RULES = array(
        'company' => array(
            'label' => '会社概要',
            'slugs' => array('company', 'about', 'aboutus', 'about-us', 'corporate', 'outline', 'gaiyou', 'profile', 'company-profile', 'operator', 'unei', 'unneisha', 'operating-company'),
            'titles' => array('会社概要', '会社案内', '運営会社', '運営者情報', '運営者', '企業情報', '会社情報'),
        ),
        'tokushoho' => array(
            'label' => '特定商取引法に基づく表記',
            'slugs' => array('tokushoho', 'tokusho', 'tokutei', 'tokuteishoutorihiki', 'law', 'legal', 'sct', 'specified-commercial'),
            'titles' => array('特定商取引', '特商法'),
        ),
        'privacy' => array(
            'label' => 'プライバシーポリシー',
            'slugs' => array('privacy', 'privacypolicy', 'privacy-policy', 'privacy_policy', 'pp', 'kojinjoho'),
            'titles' => array('プライバシー', '個人情報'),
        ),
        'terms' => array(
            'label' => '利用規約',
            'slugs' => array('terms', 'term', 'kiyaku', 'riyoukiyaku', 'rule', 'rules', 'agreement', 'tos'),
            'titles' => array('利用規約', 'ご利用規約', '会員規約'),
        ),
    );

    /** @var string 会社名(法人格を除いたコア名)。集中管理側から渡される。 */
    private $company = '';

    /** @var string 代表者名。集中管理側から渡される。 */
    private $rep = '';

    /**
     * @param string $company 会社名(照合用)。
     * @param string $rep     代表者名(照合用)。
     */
    public function __construct($company = '', $rep = '') {
        $this->company = trim((string) $company);
        $this->rep     = trim((string) $rep);
    }

    /**
     * 点検を実行する。
     *
     * @return array
     */
    public function inspect() {
        $seo_plugin = self::detect_seo_plugin();

        return array(
            'sodium'        => WPSG_SEO_Signature::available(),
            'write_enabled' => !WPSG_SEO_Signature::write_disabled(),
            'seo_plugin'    => $seo_plugin,
            'needles'       => array('company' => $this->company, 'rep' => $this->rep),
            'pages'         => $this->scan_pages($seo_plugin),
            'meta'          => $this->scan_meta($seo_plugin),
            'footer'        => $this->scan_footer(),
            'occurrences'   => $this->scan_occurrences(),
            'overrides'     => (array) get_option(WPSG_SEO_Guard::OPT_NOINDEX, array()),
        );
    }

    /**
     * 有効なSEOプラグインを判定する。
     *
     * @return string yoast|aioseo|rankmath|seopress|none
     */
    public static function detect_seo_plugin() {
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
            return 'yoast';
        }
        if (defined('AIOSEO_VERSION') || function_exists('aioseo')) {
            return 'aioseo';
        }
        if (class_exists('RankMath') || defined('RANK_MATH_VERSION')) {
            return 'rankmath';
        }
        if (defined('SEOPRESS_VERSION')) {
            return 'seopress';
        }
        return 'none';
    }

    /**
     * 対象4種のページを探し、現在の noindex 状態を返す。
     *
     * 判定できなかったページは kind=null で返す(推測で確定させない)。
     *
     * @param string $seo_plugin
     * @return array
     */
    private function scan_pages($seo_plugin) {
        $pages = get_posts(array(
            'post_type'        => array('page'),
            'post_status'      => array('publish', 'private', 'draft'),
            'posts_per_page'   => 300,
            'suppress_filters' => true,
            'no_found_rows'    => true,
        ));

        $out = array();
        foreach ($pages as $p) {
            $kind = self::classify_page($p);
            if ($kind === null) {
                continue; // 対象外のページは返さない(件数を絞る)
            }
            $content = (string) $p->post_content . ' ' . (string) $p->post_title;
            $out[] = array(
                'id'           => (int) $p->ID,
                'kind'         => $kind,
                'label'        => self::PAGE_RULES[$kind]['label'],
                'slug'         => (string) $p->post_name,
                'title'        => (string) $p->post_title,
                'status'       => (string) $p->post_status,
                'url'          => get_permalink($p->ID),
                'noindex'      => self::is_noindex((int) $p->ID, $seo_plugin),
                'noindex_src'  => self::noindex_source((int) $p->ID, $seo_plugin),
                'has_company'  => $this->contains($content, $this->company),
                'has_rep'      => $this->contains($content, $this->rep),
            );
        }
        return $out;
    }

    /**
     * ページ種別を判定する。該当なしは null。
     *
     * @param WP_Post $p
     * @return string|null
     */
    private static function classify_page($p) {
        $slug  = strtolower((string) $p->post_name);
        $title = (string) $p->post_title;

        foreach (self::PAGE_RULES as $kind => $rule) {
            foreach ($rule['slugs'] as $s) {
                if ($slug === $s || strpos($slug, $s) === 0) {
                    return $kind;
                }
            }
        }
        // スラッグで決まらなければタイトルで見る(日本語スラッグ対策)。
        foreach (self::PAGE_RULES as $kind => $rule) {
            foreach ($rule['titles'] as $t) {
                if ($title !== '' && mb_strpos($title, $t) !== false) {
                    return $kind;
                }
            }
        }
        return null;
    }

    /**
     * 現在 noindex になっているか。
     *
     * Yoast の _yoast_wpseo_meta-robots-noindex は
     *   '1' = noindex / '2' = index / '0' または未設定 = 投稿タイプ既定に従う
     * 既定はほぼ index なので、'1' のときのみ noindex とみなす。
     *
     * @param int    $post_id
     * @param string $seo_plugin
     * @return bool
     */
    public static function is_noindex($post_id, $seo_plugin) {
        // WPSG 自身の上書き設定が最優先(SEOプラグイン非導入サイト向け)。
        $ov = (array) get_option(WPSG_SEO_Guard::OPT_NOINDEX, array());
        if (in_array((int) $post_id, array_map('intval', $ov), true)) {
            return true;
        }
        if ($seo_plugin === 'yoast') {
            return get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true) === '1';
        }
        if ($seo_plugin === 'rankmath') {
            $robots = get_post_meta($post_id, 'rank_math_robots', true);
            return is_array($robots) && in_array('noindex', $robots, true);
        }
        if ($seo_plugin === 'seopress') {
            return get_post_meta($post_id, '_seopress_robots_index', true) === 'yes';
        }
        return false;
    }

    /**
     * noindex がどこで設定されているか(表示用)。
     *
     * @param int    $post_id
     * @param string $seo_plugin
     * @return string wpsg|plugin|none
     */
    private static function noindex_source($post_id, $seo_plugin) {
        $ov = (array) get_option(WPSG_SEO_Guard::OPT_NOINDEX, array());
        if (in_array((int) $post_id, array_map('intval', $ov), true)) {
            return 'wpsg';
        }
        return self::is_noindex($post_id, $seo_plugin) ? 'plugin' : 'none';
    }

    /**
     * トップページの title / description を返す(作業5の対象)。
     *
     * @param string $seo_plugin
     * @return array
     */
    private function scan_meta($seo_plugin) {
        $front_id = (int) get_option('page_on_front');
        $out = array(
            'front_page_id' => $front_id,
            'blogname'      => (string) get_option('blogname'),
            'blogdesc'      => (string) get_option('blogdescription'),
            'title'         => '',
            'desc'          => '',
            'title_source'  => '',
            'desc_source'   => '',
        );

        if ($seo_plugin === 'yoast') {
            $titles = (array) get_option('wpseo_titles', array());
            // 静的フロントページが設定されている場合、そのページ個別の指定が優先される。
            if ($front_id > 0) {
                $pt = (string) get_post_meta($front_id, '_yoast_wpseo_title', true);
                $pd = (string) get_post_meta($front_id, '_yoast_wpseo_metadesc', true);
                if ($pt !== '') {
                    $out['title'] = $pt;
                    $out['title_source'] = 'post_meta';
                }
                if ($pd !== '') {
                    $out['desc'] = $pd;
                    $out['desc_source'] = 'post_meta';
                }
            }
            if ($out['title'] === '' && isset($titles['title-home-wpseo'])) {
                $out['title'] = (string) $titles['title-home-wpseo'];
                $out['title_source'] = 'wpseo_titles';
            }
            if ($out['desc'] === '' && isset($titles['metadesc-home-wpseo'])) {
                $out['desc'] = (string) $titles['metadesc-home-wpseo'];
                $out['desc_source'] = 'wpseo_titles';
            }
        }

        $out['hit_title'] = $this->contains($out['title'], $this->company) || $this->contains($out['title'], $this->rep);
        $out['hit_desc']  = $this->contains($out['desc'], $this->company) || $this->contains($out['desc'], $this->rep);

        return $out;
    }

    /**
     * フッター(著作権表記)の状況を返す(作業4の対象)。
     *
     * テーマファイルの中身までは読まず、テーマの素性と、テーマオプションに
     * 会社名が入っていないかだけを見る。実際の出力確認は外形チェック側で行う。
     *
     * @return array
     */
    private function scan_footer() {
        $theme = wp_get_theme();
        $out = array(
            'stylesheet' => (string) $theme->get_stylesheet(),
            'template'   => (string) $theme->get_template(),
            'is_child'   => $theme->get_stylesheet() !== $theme->get_template(),
            'name'       => (string) $theme->get('Name'),
            'hits'       => array(),
        );

        // よくあるテーマオプション名を走査して、会社名を含むものを拾う。
        $candidates = array('copyright', 'footer_copyright', 'site_copyright', 'company_name', 'footer_text');
        foreach ($candidates as $key) {
            $v = get_option($key, '');
            if (is_string($v) && $v !== '' && ($this->contains($v, $this->company) || $this->contains($v, $this->rep))) {
                $out['hits'][] = array('source' => 'option:' . $key, 'value' => mb_substr($v, 0, 200));
            }
        }
        return $out;
    }

    /**
     * 本文中の会社名・代表者名の出現箇所を返す(作業3の材料)。
     *
     * 除去は手作業で行うため、ここでは「どこにあるか」を示すだけ。
     * 一括置換の材料としては使わない。
     *
     * @return array
     */
    private function scan_occurrences() {
        if ($this->company === '' && $this->rep === '') {
            return array();
        }
        global $wpdb;

        $out    = array();
        $seen   = array();
        $needles = array_filter(array($this->company, $this->rep, str_replace(array(' ', '　'), '', $this->rep)));

        foreach (array_unique($needles) as $needle) {
            if (mb_strlen($needle) < 2) {
                continue;
            }
            $like = '%' . $wpdb->esc_like($needle) . '%';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_title, post_type, post_content
                     FROM {$wpdb->posts}
                     WHERE post_status IN ('publish','private')
                       AND post_type IN ('page','post')
                       AND post_content LIKE %s
                     LIMIT 100",
                    $like
                )
            );
            foreach ($rows as $r) {
                $key = (int) $r->ID . ':' . $needle;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = array(
                    'post_id' => (int) $r->ID,
                    'type'    => (string) $r->post_type,
                    'title'   => (string) $r->post_title,
                    'needle'  => $needle,
                    'excerpt' => self::excerpt((string) $r->post_content, $needle),
                );
            }
        }
        return array_slice($out, 0, 200);
    }

    /**
     * 該当箇所の前後を抜き出す。
     *
     * @param string $content
     * @param string $needle
     * @return string
     */
    private static function excerpt($content, $needle) {
        $text = trim(wp_strip_all_tags($content));
        $pos  = mb_strpos($text, $needle);
        if ($pos === false) {
            return '';
        }
        $start = max(0, $pos - 40);
        return ($start > 0 ? '…' : '') . mb_substr($text, $start, 120) . '…';
    }

    /**
     * 文字列に対象語が含まれるか(空の対象語は常に false)。
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    private function contains($haystack, $needle) {
        $haystack = (string) $haystack;
        $needle   = trim((string) $needle);
        if ($haystack === '' || $needle === '' || mb_strlen($needle) < 2) {
            return false;
        }
        if (mb_strpos($haystack, $needle) !== false) {
            return true;
        }
        // 代表者名は姓名間のスペース有無で揺れるため、除去版でも照合する。
        $ns = str_replace(array(' ', '　'), '', $needle);
        if ($ns !== $needle && mb_strlen($ns) >= 2) {
            return mb_strpos(str_replace(array(' ', '　'), '', $haystack), $ns) !== false;
        }
        return false;
    }
}
