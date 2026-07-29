<?php
/**
 * WP Security Guard - Inspector Settings View
 *
 * セキュリティガード画面の最下部に表示する点検設定セクション。
 * 既存の <form>...</form> の外側に独立したフォームとして表示する。
 *
 * 使用変数(WPSG_Admin_Page_Extension::render_inspector_section() から渡される):
 * - $token_set    bool   トークンが設定されているか
 * - $last_access  array|null 最終アクセス情報
 * - $allowed_ips  string カンマ区切りIPリスト
 * - $plain_token  string|false 直前に生成された平文トークン (再表示用)
 * - $notice       string 通知メッセージコード
 * - $rest_url     string REST APIのベースURL
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wpsg-inspector-section" style="margin-top: 30px;">
    <hr>
    <h2>6. サイト点検設定</h2>
    <p>サイト点検モジュール用のアクセストークンと許可IPを管理します。点検モジュールは外部から HTTPS 経由で <code><?php echo esc_html($rest_url); ?></code> にアクセスし、サイトの状態を取得します。</p>

    <?php if ($notice === 'token_generated' || $notice === 'token_regenerated') : ?>
        <div class="wpsg-status-box wpsg-status-success" style="padding: 12px 15px; margin: 15px 0; background: #f0f9f0; border-left: 4px solid #00a32a; border-radius: 2px;">
            <p style="margin: 0;">トークンを<?php echo $notice === 'token_regenerated' ? '再' : ''; ?>生成しました。<strong>このトークンは1度だけ表示されます。必ず控えてください。</strong></p>
        </div>
    <?php elseif ($notice === 'token_revoked') : ?>
        <div class="wpsg-status-box wpsg-status-warning" style="padding: 12px 15px; margin: 15px 0; background: #fff8e5; border-left: 4px solid #dba617; border-radius: 2px;">
            <p style="margin: 0;">トークンを削除しました。点検APIは利用できません。</p>
        </div>
    <?php elseif ($notice === 'ips_saved') : ?>
        <div class="wpsg-status-box wpsg-status-success" style="padding: 12px 15px; margin: 15px 0; background: #f0f9f0; border-left: 4px solid #00a32a; border-radius: 2px;">
            <p style="margin: 0;">許可IPリストを保存しました。</p>
        </div>
    <?php endif; ?>

    <?php if ($plain_token !== false && !empty($plain_token)) : ?>
        <div class="wpsg-flash-token-box" style="padding: 20px; margin: 20px 0; background: #fef3c7; border: 2px solid #f59e0b; border-radius: 4px; box-shadow: 0 2px 6px rgba(0,0,0,0.05);">
            <h3 style="margin-top: 0; margin-bottom: 10px; color: #92400e; font-size: 16px;">⚠️ 新しいトークン (1度だけ表示されます)</h3>
            <p style="margin: 8px 0;">以下のトークンを安全な場所に保管してください。このページを離れると二度と表示できません。</p>
            <p style="margin: 12px 0;">
                <code style="display: block; padding: 12px; background: #ffffff; border: 1px solid #d4d4d4; word-break: break-all; font-size: 14px; user-select: all; color: #1f2937; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;">
                    <?php echo esc_html($plain_token); ?>
                </code>
            </p>
            <p style="margin: 8px 0;"><strong>使い方:</strong> HTTPリクエストヘッダーに以下を付けてください。</p>
            <p style="margin: 8px 0;">
                <code style="background: #ffffff; padding: 4px 8px; border: 1px solid #d4d4d4;">Authorization: Bearer <?php echo esc_html(substr($plain_token, 0, 8)); ?>...</code>
            </p>
            <p style="margin: 8px 0;"><strong>動作確認用 curl コマンド:</strong></p>
            <p style="margin: 8px 0;">
                <code style="display: block; padding: 12px; background: #ffffff; border: 1px solid #d4d4d4; word-break: break-all; font-size: 12px;">
                    curl -H "Authorization: Bearer <?php echo esc_html($plain_token); ?>" "<?php echo esc_html($rest_url); ?>core"
                </code>
            </p>
        </div>
    <?php endif; ?>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row">点検アクセストークン</th>
            <td>
                <?php if ($token_set) : ?>
                    <p>
                        <span style="color: #008000; font-weight: bold;">✓ 設定済み</span>
                        (ハッシュ値で保存されており、平文を再表示することはできません)
                    </p>
                <?php else : ?>
                    <p>
                        <span style="color: #b30000; font-weight: bold;">✗ 未設定</span>
                        - 「トークンを生成」ボタンを押してください
                    </p>
                <?php endif; ?>

                <form method="post" action="" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('wpsg_inspect_token_action', 'wpsg_inspect_nonce'); ?>
                    <input type="hidden" name="wpsg_inspect_action" value="<?php echo $token_set ? 'regenerate' : 'generate'; ?>">
                    <button type="submit" class="button button-primary"
                        onclick="return confirm('<?php echo $token_set ? 'トークンを再生成すると、現在のトークンは即座に無効化されます。続行しますか？' : 'トークンを生成しますか？'; ?>');">
                        <?php echo $token_set ? 'トークンを再生成' : 'トークンを生成'; ?>
                    </button>
                </form>

                <?php if ($token_set) : ?>
                    <form method="post" action="" style="display: inline-block;">
                        <?php wp_nonce_field('wpsg_inspect_token_action', 'wpsg_inspect_nonce'); ?>
                        <input type="hidden" name="wpsg_inspect_action" value="revoke">
                        <button type="submit" class="button"
                            onclick="return confirm('トークンを削除すると、点検APIは利用できなくなります。続行しますか？');">
                            トークンを削除
                        </button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>

        <?php if ($token_set && is_array($last_access)) : ?>
        <tr>
            <th scope="row">最終アクセス</th>
            <td>
                <p>
                    日時: <?php echo isset($last_access['timestamp']) ? esc_html($last_access['timestamp']) : '不明'; ?><br>
                    IP: <code><?php echo isset($last_access['ip']) ? esc_html($last_access['ip']) : '不明'; ?></code>
                </p>
            </td>
        </tr>
        <?php endif; ?>
    </table>

    <h3>許可IP制限 (任意)</h3>
    <form method="post" action="">
        <?php wp_nonce_field('wpsg_inspect_ips_save', 'wpsg_inspect_ips_nonce'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="wpsg_inspect_allowed_ips">許可IPリスト</label>
                </th>
                <td>
                    <input type="text" id="wpsg_inspect_allowed_ips"
                        name="wpsg_inspect_allowed_ips"
                        value="<?php echo esc_attr($allowed_ips); ?>"
                        class="large-text"
                        placeholder="例: 203.0.113.42, 198.51.100.10">
                    <p class="description">
                        カンマ区切りで指定してください。<strong>空欄の場合は制限なし</strong>です。<br>
                        無効なIPは保存時に除外されます。
                    </p>
                </td>
            </tr>
        </table>
        <p class="submit">
            <button type="submit" name="wpsg_inspect_save_ips" class="button button-primary">許可IP設定を保存</button>
        </p>
    </form>

    <h3>エンドポイント一覧</h3>
    <table class="widefat" style="max-width: 800px;">
        <thead>
            <tr>
                <th>エンドポイント</th>
                <th>認証</th>
                <th>用途</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>GET <?php echo esc_html($rest_url); ?>health</code></td>
                <td>不要</td>
                <td>軽量ヘルスチェック</td>
            </tr>
            <tr>
                <td><code>GET <?php echo esc_html($rest_url); ?>all</code></td>
                <td>必要</td>
                <td>全項目を一括取得</td>
            </tr>
            <tr>
                <td><code>GET <?php echo esc_html($rest_url); ?>core</code></td>
                <td>必要</td>
                <td>WP/PHP/MySQL情報</td>
            </tr>
            <tr>
                <td><code>GET <?php echo esc_html($rest_url); ?>theme</code></td>
                <td>必要</td>
                <td>テーマ情報</td>
            </tr>
            <tr>
                <td><code>GET <?php echo esc_html($rest_url); ?>plugins</code></td>
                <td>必要</td>
                <td>プラグイン一覧 (旧版残存検出含む)</td>
            </tr>
            <tr>
                <td><code>GET <?php echo esc_html($rest_url); ?>features</code></td>
                <td>必要</td>
                <td>機能フラグ (保存値・検出値・乖離)</td>
            </tr>
            <tr>
                <td><code>GET <?php echo esc_html($rest_url); ?>membership</code></td>
                <td>必要</td>
                <td>会員管理 (UM/WP Full Stripe/WC/WC Vendors/WP Crowdfunding/BankPay)</td>
            </tr>
            <tr>
                <td><code>GET <?php echo esc_html($rest_url); ?>stripe</code></td>
                <td>必要</td>
                <td>決済設定 (機微情報マスク済み)</td>
            </tr>
        </tbody>
    </table>

    <p class="description" style="margin-top: 15px;">
        点検モジュール バージョン: <code><?php echo esc_html(WPSG_Site_Inspector::get_version()); ?></code>
    </p>
</div>
