<?php
/**
 * WP Security Guard - Feature Storage
 *
 * サイトごとの機能フラグ確定値を wp_options に保存・取得する。
 *
 * 保存形式 (wp_options.wpsg_site_features):
 * [
 *     'system_type'   => 'matching',      // システム種別
 *     'site_label'    => 'matching-001',  // 任意ラベル(運用用)
 *     'features'      => [                // 機能フラグの確定値
 *         'membership' => true,
 *         'forum'      => false,
 *         ...
 *     ],
 *     'last_detected' => [                // 最終自動検出時の値
 *         'membership' => true,
 *         ...
 *     ],
 *     'last_synced_at'  => '2026-04-30T...',
 *     'last_updated_at' => '2026-04-30T...',
 *     'notes'           => '備考',
 * ]
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Feature_Storage {

    /** wp_options キー */
    const OPTION_NAME = 'wpsg_site_features';

    /**
     * 保存値を取得する
     *
     * @return array 保存データ(未設定なら空配列)
     */
    public static function get() {
        $data = get_option(self::OPTION_NAME, array());
        return is_array($data) ? $data : array();
    }

    /**
     * 設定済みかどうか
     *
     * @return bool
     */
    public static function is_configured() {
        $data = self::get();
        return !empty($data['system_type']) && !empty($data['features']);
    }

    /**
     * システム種別を取得
     *
     * @return string|null
     */
    public static function get_system_type() {
        $data = self::get();
        return isset($data['system_type']) ? $data['system_type'] : null;
    }

    /**
     * サイトラベルを取得
     *
     * @return string|null
     */
    public static function get_site_label() {
        $data = self::get();
        return isset($data['site_label']) ? $data['site_label'] : null;
    }

    /**
     * 確定機能フラグを取得 (全機能をbool値で返す)
     *
     * @return array [feature_id => bool]
     */
    public static function get_features() {
        $data = self::get();
        $saved = isset($data['features']) && is_array($data['features']) ? $data['features'] : array();
        // 未保存の機能はfalseを補う
        $all = array();
        foreach (WPSG_Feature_Registry::get_all_feature_ids() as $feature_id) {
            $all[$feature_id] = isset($saved[$feature_id]) ? (bool) $saved[$feature_id] : false;
        }
        return $all;
    }

    /**
     * 最終検出値を取得
     *
     * @return array [feature_id => bool]
     */
    public static function get_last_detected() {
        $data = self::get();
        return isset($data['last_detected']) && is_array($data['last_detected'])
            ? $data['last_detected']
            : array();
    }

    /**
     * 設定を保存する
     *
     * @param array $update 上書きしたいキーのみ含めた配列
     *                      'system_type', 'site_label', 'features', 'notes' を受け付ける
     * @return bool 成功時 true
     */
    public static function save(array $update) {
        $current = self::get();
        $sanitized = array();

        // system_type
        if (array_key_exists('system_type', $update)) {
            $st = is_string($update['system_type']) ? $update['system_type'] : '';
            if (WPSG_Feature_Registry::is_valid_system_type($st)) {
                $sanitized['system_type'] = $st;
            } elseif ($st === '') {
                $sanitized['system_type'] = '';
            }
        }

        // site_label (英数字+ハイフン+アンダースコアのみ)
        if (array_key_exists('site_label', $update)) {
            $label = is_string($update['site_label']) ? $update['site_label'] : '';
            $label = preg_replace('/[^a-zA-Z0-9_\-]/', '', $label);
            $label = substr($label, 0, 64);
            $sanitized['site_label'] = $label;
        }

        // features
        if (array_key_exists('features', $update) && is_array($update['features'])) {
            $features = array();
            foreach (WPSG_Feature_Registry::get_all_feature_ids() as $feature_id) {
                $features[$feature_id] = !empty($update['features'][$feature_id]);
            }
            $sanitized['features'] = $features;
        }

        // notes (HTMLタグ除去、長さ制限)
        if (array_key_exists('notes', $update)) {
            $notes = is_string($update['notes']) ? $update['notes'] : '';
            $notes = wp_strip_all_tags($notes);
            $notes = substr($notes, 0, 1000);
            $sanitized['notes'] = $notes;
        }

        // last_detected
        if (array_key_exists('last_detected', $update) && is_array($update['last_detected'])) {
            $last_detected = array();
            foreach (WPSG_Feature_Registry::get_all_feature_ids() as $feature_id) {
                if (isset($update['last_detected'][$feature_id])) {
                    $last_detected[$feature_id] = (bool) $update['last_detected'][$feature_id];
                }
            }
            $sanitized['last_detected'] = $last_detected;
            $sanitized['last_synced_at'] = current_time('c');
        }

        $merged = array_merge($current, $sanitized);
        $merged['last_updated_at'] = current_time('c');

        // autoload=false (REST APIアクセス時のみ読まれる)
        return update_option(self::OPTION_NAME, $merged, false);
    }

    /**
     * 設定を削除する (リセット用)
     */
    public static function delete() {
        delete_option(self::OPTION_NAME);
    }

    /**
     * 保存値と検出値の差分を計算する
     *
     * @param array $saved    [feature_id => bool]
     * @param array $detected [feature_id => ['detected' => bool, 'signals' => [], 'confidence' => string]]
     * @return array [['feature' => string, 'saved' => bool, 'detected' => bool, 'message' => string]]
     */
    public static function compute_discrepancies(array $saved, array $detected) {
        $discrepancies = array();
        foreach (WPSG_Feature_Registry::get_all_feature_ids() as $feature_id) {
            $saved_val    = !empty($saved[$feature_id]);
            $detected_val = !empty($detected[$feature_id]['detected']);
            $confidence   = isset($detected[$feature_id]['confidence']) ? $detected[$feature_id]['confidence'] : 'none';

            if ($saved_val !== $detected_val) {
                // confidence が none の場合は乖離として扱わない (検出不可なので)
                if ($confidence === 'none') {
                    continue;
                }
                $label = WPSG_Feature_Registry::get_feature_label($feature_id);
                $signals = isset($detected[$feature_id]['signals']) ? $detected[$feature_id]['signals'] : array();
                if ($saved_val && !$detected_val) {
                    $message = sprintf(
                        '%s: 保存値はONだが、検出されませんでした',
                        $label
                    );
                } else {
                    $signal_str = !empty($signals) ? implode(', ', $signals) : '不明';
                    $message = sprintf(
                        '%s: 保存値はOFFだが、検出されました(シグナル: %s)',
                        $label,
                        $signal_str
                    );
                }
                $discrepancies[] = array(
                    'feature'    => $feature_id,
                    'label'      => $label,
                    'saved'      => $saved_val,
                    'detected'   => $detected_val,
                    'confidence' => $confidence,
                    'signals'    => $signals,
                    'message'    => $message,
                );
            }
        }
        return $discrepancies;
    }
}
