<?php
/**
 * WP Security Guard - Features Inspector
 *
 * 機能フラグの保存値・自動検出値・乖離情報を統合して返す。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Inspector_Features extends WPSG_Inspector_Base {

    /**
     * @inheritDoc
     */
    public function inspect() {
        $configured = WPSG_Feature_Storage::is_configured();
        $detector = new WPSG_Feature_Detector();
        $detected = $detector->detect_all();

        if (!$configured) {
            // 未設定の場合 - 自動検出値のみ提供し、警告を出す
            $this->add_warning(
                'warning',
                'features_not_configured',
                '機能フラグが未設定です。管理画面で初期設定を行ってください'
            );
            $features = array();
            foreach (WPSG_Feature_Registry::get_all_feature_ids() as $feature_id) {
                $features[$feature_id] = array(
                    'label'      => WPSG_Feature_Registry::get_feature_label($feature_id),
                    'category'   => WPSG_Feature_Registry::get_feature_category($feature_id),
                    'saved'      => null,
                    'detected'   => $detected[$feature_id]['detected'],
                    'signals'    => $detected[$feature_id]['signals'],
                    'confidence' => $detected[$feature_id]['confidence'],
                    'in_sync'    => null,
                );
            }
            return array(
                'configured'              => false,
                'system_type'             => null,
                'site_label'              => null,
                'features'                => $features,
                'discrepancies'           => array(),
                'summary'                 => $this->summarize(null, $detected),
                'last_updated_at'         => null,
                'last_synced_at'          => null,
                'available_system_types'  => WPSG_Feature_Registry::get_system_types(),
                'available_categories'    => WPSG_Feature_Registry::get_categories(),
            );
        }

        // 設定済みの場合 - 保存値・検出値・乖離を統合
        $saved = WPSG_Feature_Storage::get_features();
        $stored_data = WPSG_Feature_Storage::get();
        $system_type = isset($stored_data['system_type']) ? $stored_data['system_type'] : null;
        $site_label  = isset($stored_data['site_label']) ? $stored_data['site_label'] : null;
        $notes       = isset($stored_data['notes']) ? $stored_data['notes'] : null;
        $last_updated_at = isset($stored_data['last_updated_at']) ? $stored_data['last_updated_at'] : null;
        $last_synced_at  = isset($stored_data['last_synced_at']) ? $stored_data['last_synced_at'] : null;

        $discrepancies = WPSG_Feature_Storage::compute_discrepancies($saved, $detected);

        $features = array();
        foreach (WPSG_Feature_Registry::get_all_feature_ids() as $feature_id) {
            $saved_val    = !empty($saved[$feature_id]);
            $detected_val = $detected[$feature_id]['detected'];
            $confidence   = $detected[$feature_id]['confidence'];
            $in_sync = $confidence === 'none'
                ? null  // 検出不可は判定しない
                : ($saved_val === $detected_val);
            $features[$feature_id] = array(
                'label'      => WPSG_Feature_Registry::get_feature_label($feature_id),
                'category'   => WPSG_Feature_Registry::get_feature_category($feature_id),
                'saved'      => $saved_val,
                'detected'   => $detected_val,
                'signals'    => $detected[$feature_id]['signals'],
                'confidence' => $confidence,
                'in_sync'    => $in_sync,
            );
        }

        if (count($discrepancies) > 0) {
            $this->add_warning(
                'warning',
                'feature_discrepancy',
                sprintf('%d件の機能フラグで保存値と検出値が乖離しています', count($discrepancies)),
                array('features' => array_column($discrepancies, 'feature'))
            );
        }

        // システム種別が無効/欠落
        if (!WPSG_Feature_Registry::is_valid_system_type($system_type)) {
            $this->add_warning(
                'warning',
                'invalid_system_type',
                sprintf('システム種別が無効です: %s', $system_type ?: '(空)')
            );
        }

        return array(
            'configured'              => true,
            'system_type'             => $system_type,
            'system_type_label'       => WPSG_Feature_Registry::is_valid_system_type($system_type)
                ? WPSG_Feature_Registry::get_system_type_label($system_type) : null,
            'site_label'              => $site_label,
            'notes'                   => $notes,
            'features'                => $features,
            'discrepancies'           => $discrepancies,
            'summary'                 => $this->summarize($saved, $detected),
            'last_updated_at'         => $last_updated_at,
            'last_synced_at'          => $last_synced_at,
            'available_system_types'  => WPSG_Feature_Registry::get_system_types(),
            'available_categories'    => WPSG_Feature_Registry::get_categories(),
        );
    }

    /**
     * 統計情報を計算
     *
     * @param array|null $saved
     * @param array $detected
     * @return array
     */
    private function summarize($saved, $detected) {
        $total          = count(WPSG_Feature_Registry::get_all_feature_ids());
        $saved_enabled  = is_array($saved) ? count(array_filter($saved)) : null;
        $detected_enabled = 0;
        $high_conf      = 0;
        $no_signal      = 0;
        foreach ($detected as $feature_id => $info) {
            if (!empty($info['detected'])) {
                $detected_enabled++;
            }
            if (($info['confidence'] ?? 'none') === 'high') {
                $high_conf++;
            }
            if (($info['confidence'] ?? 'none') === 'none') {
                $no_signal++;
            }
        }
        return array(
            'total_features'       => $total,
            'saved_enabled'        => $saved_enabled,
            'detected_enabled'     => $detected_enabled,
            'high_confidence_count'=> $high_conf,
            'no_signal_count'      => $no_signal,
        );
    }
}
