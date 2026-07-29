<?php
/**
 * WP Security Guard - Features Editor View
 *
 * セキュリティガード画面の最下部に表示する機能フラグ編集セクション。
 *
 * 使用変数(WPSG_Admin_Page_Extension::render_features_section() から渡される):
 * - $is_configured        bool   設定済みかどうか
 * - $current_system_type  string 現在のシステム種別ID
 * - $current_site_label   string 現在のサイトラベル
 * - $current_features     array  [feature_id => bool]
 * - $current_notes        string 備考
 * - $last_synced_at       string|null 最終自動検出日時
 * - $last_updated_at      string|null 最終更新日時
 * - $detected_results     array  [feature_id => ['detected' => bool, 'signals' => [], 'confidence' => string]]
 * - $notice               string 通知メッセージコード
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$grouped = WPSG_Feature_Registry::get_features_grouped_by_category();
$categories = WPSG_Feature_Registry::get_categories();
$system_types = WPSG_Feature_Registry::get_system_types();

// 信頼度の表示ラベル
$confidence_labels = array(
    'high'   => array('label' => '検出', 'color' => '#008000'),
    'medium' => array('label' => '推定', 'color' => '#ff9800'),
    'low'    => array('label' => '弱',   'color' => '#9c27b0'),
    'none'   => array('label' => '不可', 'color' => '#666'),
);
?>

<div class="wpsg-features-section" style="margin-top: 30px;">
    <hr>
    <h2>7. サイト機能フラグ管理</h2>
    <p>このサイトに実装されている機能の設定値を管理します。点検システムは保存値と自動検出値を比較し、乖離を通知します。</p>

    <?php if ($notice === 'features_saved') : ?>
        <div class="wpsg-status-box wpsg-status-success" style="padding: 12px 15px; margin: 15px 0; background: #f0f9f0; border-left: 4px solid #00a32a; border-radius: 2px;">
            <p style="margin: 0;">機能フラグを保存しました。</p>
        </div>
    <?php elseif ($notice === 'features_autodetected') : ?>
        <div class="wpsg-status-box wpsg-status-info" style="padding: 12px 15px; margin: 15px 0; background: #e8f4fd; border-left: 4px solid #2271b1; border-radius: 2px;">
            <p style="margin: 0;">自動検出結果を機能フラグに反映しました。内容を確認し、必要に応じて手動で調整してから「保存」してください。</p>
        </div>
    <?php endif; ?>

    <?php if (!$is_configured) : ?>
        <div class="wpsg-status-box wpsg-status-warning" style="padding: 12px 15px; margin: 15px 0; background: #fff8e5; border-left: 4px solid #dba617; border-radius: 2px;">
            <p style="margin: 0;">
                <strong>機能フラグが未設定です。</strong>
                以下のフォームでシステム種別を選び、「自動検出してプリセット」ボタンを押してから内容を確認・保存してください。
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field('wpsg_features_save', 'wpsg_features_nonce'); ?>

        <h3>基本情報</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="wpsg_system_type">システム種別 <span style="color: red;">*</span></label></th>
                <td>
                    <select id="wpsg_system_type" name="wpsg_system_type">
                        <option value="">-- 選択してください --</option>
                        <?php foreach ($system_types as $st_id => $st_label) : ?>
                            <option value="<?php echo esc_attr($st_id); ?>" <?php selected($current_system_type, $st_id); ?>>
                                <?php echo esc_html($st_label); ?> (<?php echo esc_html($st_id); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpsg_site_label">サイトラベル</label></th>
                <td>
                    <input type="text" id="wpsg_site_label" name="wpsg_site_label"
                        value="<?php echo esc_attr($current_site_label); ?>"
                        class="regular-text" maxlength="64"
                        placeholder="例: matching-001">
                    <p class="description">英数字・ハイフン・アンダースコアのみ。サイト識別用の任意ラベル(運用システムでサイトを区別するため)。</p>
                </td>
            </tr>
            <?php if ($last_synced_at) : ?>
            <tr>
                <th scope="row">最終自動検出</th>
                <td><code><?php echo esc_html($last_synced_at); ?></code></td>
            </tr>
            <?php endif; ?>
            <?php if ($last_updated_at) : ?>
            <tr>
                <th scope="row">最終更新</th>
                <td><code><?php echo esc_html($last_updated_at); ?></code></td>
            </tr>
            <?php endif; ?>
        </table>

        <h3>機能フラグ</h3>
        <p>各機能のON/OFFを設定します。「検出」列は自動検出の結果です。</p>

        <p>
            <strong>凡例:</strong>
            <span style="color: #008000;">●検出</span>(プラグイン等で検出)
            <span style="color: #ff9800;">●推定</span>(関連シグナル検出)
            <span style="color: #9c27b0;">●弱</span>(ヒューリスティックのみ)
            <span style="color: #666;">●不可</span>(自動検出不可)
        </p>

        <table class="widefat striped" style="max-width: 900px;">
            <thead>
                <tr>
                    <th style="width: 25%;">機能</th>
                    <th style="width: 10%;">ON/OFF</th>
                    <th style="width: 10%;">検出結果</th>
                    <th style="width: 10%;">信頼度</th>
                    <th>検出シグナル</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat_id => $cat_label) : ?>
                    <?php if (empty($grouped[$cat_id])) continue; ?>
                    <tr style="background: #f0f0f1;">
                        <td colspan="5"><strong><?php echo esc_html($cat_label); ?></strong></td>
                    </tr>
                    <?php foreach ($grouped[$cat_id] as $feature_id => $feature_label) :
                        $current_value = !empty($current_features[$feature_id]);
                        $detected = $detected_results[$feature_id] ?? array(
                            'detected' => false, 'signals' => array(), 'confidence' => 'none'
                        );
                        $is_discrepant = $is_configured
                            && ($detected['confidence'] !== 'none')
                            && ($current_value !== !empty($detected['detected']));
                        $row_style = $is_discrepant ? ' style="background-color: #fff3cd;"' : '';
                        $conf_info = $confidence_labels[$detected['confidence']] ?? $confidence_labels['none'];
                    ?>
                    <tr<?php echo $row_style; ?>>
                        <td>
                            <code><?php echo esc_html($feature_id); ?></code><br>
                            <?php echo esc_html($feature_label); ?>
                            <?php if ($is_discrepant) : ?>
                                <br><small style="color: #b30000;">⚠ 保存値と検出値が乖離</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <label>
                                <input type="checkbox"
                                    name="wpsg_features[<?php echo esc_attr($feature_id); ?>]"
                                    value="1"
                                    <?php checked($current_value); ?>>
                                <?php echo $current_value ? 'ON' : 'OFF'; ?>
                            </label>
                        </td>
                        <td>
                            <?php if ($detected['detected']) : ?>
                                <span style="color: #008000; font-weight: bold;">✓ あり</span>
                            <?php else : ?>
                                <span style="color: #999;">なし</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="color: <?php echo esc_attr($conf_info['color']); ?>;">
                                ●<?php echo esc_html($conf_info['label']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($detected['signals'])) : ?>
                                <code style="font-size: 11px;">
                                    <?php echo esc_html(implode(', ', $detected['signals'])); ?>
                                </code>
                            <?php else : ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3>備考</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="wpsg_features_notes">メモ</label></th>
                <td>
                    <textarea id="wpsg_features_notes" name="wpsg_features_notes"
                        class="large-text" rows="3"
                        maxlength="1000"
                        placeholder="例: チャットは独自実装、フォーラムは未使用"><?php echo esc_textarea($current_notes); ?></textarea>
                    <p class="description">運用上のメモを記録します(1000文字まで)。</p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" name="wpsg_features_save" class="button button-primary">機能フラグを保存</button>
            <button type="submit" name="wpsg_features_autodetect" class="button"
                onclick="return confirm('現在の自動検出結果でフォームを上書きします。続行しますか？');">
                自動検出してプリセット
            </button>
        </p>
    </form>

    <h3>システム種別のデフォルト機能</h3>
    <p>新規サイト初期登録時の参考用です。</p>
    <table class="widefat" style="max-width: 800px;">
        <thead>
            <tr>
                <th>システム種別</th>
                <th>デフォルト有効機能</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($system_types as $st_id => $st_label) : ?>
                <?php $defaults = WPSG_Feature_Registry::get_default_features_for_system($st_id); ?>
                <tr>
                    <td>
                        <code><?php echo esc_html($st_id); ?></code><br>
                        <?php echo esc_html($st_label); ?>
                    </td>
                    <td>
                        <small><?php echo esc_html(implode(', ', $defaults)); ?></small>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
