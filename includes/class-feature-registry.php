<?php
/**
 * WP Security Guard - Feature Registry
 *
 * 機能定義のマスターデータ。
 * 26機能 × 7カテゴリ × 6システム種別を管理する。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Feature_Registry {

    /**
     * 機能カテゴリ定義
     *
     * @var array
     */
    const CATEGORIES = array(
        'general'       => '共通系',
        'communication' => 'コミュニケーション系',
        'matching'      => 'マッチング系',
        'commerce'      => 'EC・決済系',
        'content'       => 'コンテンツ系',
        'crowdfunding'  => 'CF系',
        'other'         => 'その他',
    );

    /**
     * 機能定義 (ID => 設定)
     *
     * @var array
     */
    const FEATURES = array(
        // 共通系
        'membership'      => array('label' => '会員機能',         'category' => 'general'),
        'login'           => array('label' => 'ログイン',         'category' => 'general'),
        'mypage'          => array('label' => 'マイページ',       'category' => 'general'),
        'profile'         => array('label' => 'プロフィール編集', 'category' => 'general'),

        // コミュニケーション系
        'forum'           => array('label' => 'フォーラム',             'category' => 'communication'),
        'chat'            => array('label' => 'チャット',               'category' => 'communication'),
        'dm'              => array('label' => 'ダイレクトメッセージ',   'category' => 'communication'),
        'comment'         => array('label' => 'コメント',               'category' => 'communication'),
        'notification'    => array('label' => '通知',                   'category' => 'communication'),

        // マッチング系
        'matching'        => array('label' => 'マッチング',         'category' => 'matching'),
        'profile_search'  => array('label' => 'プロフィール検索',   'category' => 'matching'),
        'favorites'       => array('label' => 'お気に入り',         'category' => 'matching'),

        // EC・決済系
        'shop'            => array('label' => 'ショップ',                       'category' => 'commerce'),
        'cart'            => array('label' => 'カート',                         'category' => 'commerce'),
        'subscription'    => array('label' => 'サブスクリプション',             'category' => 'commerce'),
        'stripe'          => array('label' => 'Stripe決済',                     'category' => 'commerce'),
        'paypal'          => array('label' => 'PayPal決済',                     'category' => 'commerce'),
        'bank_transfer'   => array('label' => '銀行振込決済',                   'category' => 'commerce'),
        'multi_vendor'    => array('label' => 'マルチベンダー(複数販売者)',     'category' => 'commerce'),

        // コンテンツ系
        'content_view'    => array('label' => 'コンテンツ閲覧',     'category' => 'content'),
        'paid_content'    => array('label' => '有料コンテンツ',     'category' => 'content'),
        'live_streaming'  => array('label' => 'ライブ配信',         'category' => 'content'),
        'live_commerce'   => array('label' => 'ライブコマース',     'category' => 'content'),

        // CF系
        'crowdfunding'    => array('label' => 'クラウドファンディング', 'category' => 'crowdfunding'),
        'project'         => array('label' => 'プロジェクト管理',       'category' => 'crowdfunding'),
        'reward'          => array('label' => 'リワード',               'category' => 'crowdfunding'),

        // その他
        'review'          => array('label' => 'レビュー',       'category' => 'other'),
        'rating'          => array('label' => '評価・スコア',   'category' => 'other'),
    );

    /**
     * システム種別定義 (ID => 表示名)
     *
     * @var array
     */
    const SYSTEM_TYPES = array(
        'matching'      => 'マッチングシステム',
        'salon'         => 'オンラインサロンシステム',
        'live_commerce' => 'ライブコマースシステム',
        'ec_mall'       => 'ECモールシステム',
        'crm_subsc'     => 'CRMサブスクシステム',
        'crowdfunding'  => 'ライブクラウドファンディングシステム',
    );

    /**
     * システム種別ごとのデフォルト機能リスト
     * 新規サイト初期設定時に「とりあえずONにする」機能群
     *
     * @var array
     */
    const SYSTEM_DEFAULTS = array(
        'matching' => array(
            'membership', 'login', 'mypage', 'profile',
            'matching', 'profile_search', 'chat', 'favorites',
            'subscription', 'notification',
        ),
        'salon' => array(
            'membership', 'login', 'mypage', 'profile',
            'forum', 'paid_content', 'comment',
            'subscription', 'notification',
        ),
        'live_commerce' => array(
            'membership', 'login', 'mypage',
            'shop', 'cart', 'live_commerce', 'live_streaming',
            'stripe', 'review', 'rating',
        ),
        'ec_mall' => array(
            'membership', 'login', 'mypage',
            'shop', 'cart', 'multi_vendor',
            'stripe', 'review', 'rating',
        ),
        'crm_subsc' => array(
            'membership', 'login', 'mypage', 'profile',
            'subscription', 'stripe', 'notification',
        ),
        'crowdfunding' => array(
            'membership', 'login', 'mypage',
            'crowdfunding', 'project', 'reward',
            'shop', 'stripe', 'paypal',
        ),
    );

    /**
     * 全カテゴリを取得
     *
     * @return array [category_id => label]
     */
    public static function get_categories() {
        return self::CATEGORIES;
    }

    /**
     * 全機能IDを取得
     *
     * @return array
     */
    public static function get_all_feature_ids() {
        return array_keys(self::FEATURES);
    }

    /**
     * 機能の表示名を取得
     *
     * @param string $feature_id
     * @return string
     */
    public static function get_feature_label($feature_id) {
        return isset(self::FEATURES[$feature_id]['label'])
            ? self::FEATURES[$feature_id]['label']
            : $feature_id;
    }

    /**
     * 機能のカテゴリを取得
     *
     * @param string $feature_id
     * @return string
     */
    public static function get_feature_category($feature_id) {
        return isset(self::FEATURES[$feature_id]['category'])
            ? self::FEATURES[$feature_id]['category']
            : 'other';
    }

    /**
     * カテゴリの表示名を取得
     *
     * @param string $category_id
     * @return string
     */
    public static function get_category_label($category_id) {
        return isset(self::CATEGORIES[$category_id])
            ? self::CATEGORIES[$category_id]
            : $category_id;
    }

    /**
     * 機能をカテゴリ別にグループ化したものを取得
     *
     * @return array [category_id => [feature_id => label]]
     */
    public static function get_features_grouped_by_category() {
        $grouped = array();
        foreach (self::CATEGORIES as $cat_id => $cat_label) {
            $grouped[$cat_id] = array();
        }
        foreach (self::FEATURES as $feature_id => $info) {
            $cat = isset($info['category']) ? $info['category'] : 'other';
            $grouped[$cat][$feature_id] = $info['label'];
        }
        return $grouped;
    }

    /**
     * 全システム種別を取得
     *
     * @return array
     */
    public static function get_system_types() {
        return self::SYSTEM_TYPES;
    }

    /**
     * システム種別の表示名を取得
     *
     * @param string $system_type
     * @return string
     */
    public static function get_system_type_label($system_type) {
        return isset(self::SYSTEM_TYPES[$system_type])
            ? self::SYSTEM_TYPES[$system_type]
            : $system_type;
    }

    /**
     * システム種別のデフォルト機能リストを取得
     *
     * @param string $system_type
     * @return array 機能ID配列(デフォルトでON)
     */
    public static function get_default_features_for_system($system_type) {
        return isset(self::SYSTEM_DEFAULTS[$system_type])
            ? self::SYSTEM_DEFAULTS[$system_type]
            : array();
    }

    /**
     * 機能IDが妥当か検証
     *
     * @param string $feature_id
     * @return bool
     */
    public static function is_valid_feature($feature_id) {
        return is_string($feature_id) && isset(self::FEATURES[$feature_id]);
    }

    /**
     * システム種別が妥当か検証
     *
     * @param string $system_type
     * @return bool
     */
    public static function is_valid_system_type($system_type) {
        return is_string($system_type) && isset(self::SYSTEM_TYPES[$system_type]);
    }
}
