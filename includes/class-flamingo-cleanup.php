<?php
/**
 * WP Security Guard - Flamingo データ一括削除
 *
 * Flamingo が蓄積した受信メッセージ(flamingo_inbound)とアドレス帳(flamingo_contact)を、
 * 日付条件で一括「完全削除」する管理画面機能。Ajaxバッチ処理・進捗表示・実行ログ付き。
 *
 * @package WPSecurityGuard
 * @since 2.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPSG_Flamingo_Cleanup {

    /** 対象投稿タイプ => 表示名。 */
    const POST_TYPES = array(
        'flamingo_inbound' => '受信メッセージ',
        'flamingo_contact' => 'アドレス帳',
    );

    /** 1バッチの削除件数。 */
    const BATCH = 250;

    /** 実行ログの option キーと保持件数。 */
    const OPT_LOG = 'wpsg_flamingo_log';
    const LOG_MAX = 20;

    const NONCE = 'wpsg_flamingo';

    public static function init() {
        if (!is_admin()) {
            return;
        }
        add_action('admin_menu', array(__CLASS__, 'menu'), 20);
        add_action('wp_ajax_wpsg_flamingo_preview', array(__CLASS__, 'ajax_preview'));
        add_action('wp_ajax_wpsg_flamingo_delete',  array(__CLASS__, 'ajax_delete'));
        add_action('wp_ajax_wpsg_flamingo_log',     array(__CLASS__, 'ajax_log'));
    }

    public static function menu() {
        add_submenu_page(
            'wp-security-guard',
            'Flamingo データ削除',
            'Flamingo削除',
            'manage_options',
            'wpsg-flamingo-cleanup',
            array(__CLASS__, 'render')
        );
    }

    // ====================================================================
    // 条件のパース・SQL構築
    // ====================================================================

    /**
     * リクエストから削除条件を組み立てる。
     *
     * @return array{targets:string[],mode:string,before:string,days:int}
     */
    private static function parse_condition() {
        $targets = array();
        $req = isset($_POST['targets']) ? (array) wp_unslash($_POST['targets']) : array(); // phpcs:ignore
        foreach ($req as $t) {
            $t = sanitize_key($t);
            if (isset(self::POST_TYPES[$t])) {
                $targets[] = $t;
            }
        }
        $mode = isset($_POST['date_mode']) ? sanitize_key(wp_unslash($_POST['date_mode'])) : 'all'; // phpcs:ignore
        if (!in_array($mode, array('all', 'before', 'olderthan'), true)) {
            $mode = 'all';
        }
        $before = isset($_POST['date_before']) ? sanitize_text_field(wp_unslash($_POST['date_before'])) : ''; // phpcs:ignore
        // YYYY-MM-DD のみ許可。
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
            $before = '';
        }
        $days = isset($_POST['date_days']) ? absint(wp_unslash($_POST['date_days'])) : 0; // phpcs:ignore
        return array('targets' => $targets, 'mode' => $mode, 'before' => $before, 'days' => $days);
    }

    /**
     * post_date に対する WHERE 追加句とパラメータを返す。
     *
     * @param array $cond
     * @return array{0:string,1:array} [sql断片(先頭にAND付き or 空), params]
     */
    private static function date_where(array $cond) {
        if ('before' === $cond['mode'] && '' !== $cond['before']) {
            return array(' AND post_date <= %s', array($cond['before'] . ' 23:59:59'));
        }
        if ('olderthan' === $cond['mode'] && $cond['days'] > 0) {
            $cut = gmdate('Y-m-d H:i:s', current_time('timestamp') - ($cond['days'] * DAY_IN_SECONDS)); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
            return array(' AND post_date < %s', array($cut));
        }
        return array('', array());
    }

    /**
     * 条件に一致する件数（全ステータス＝post_status非フィルタで trash/spam も含む）。
     */
    private static function count_type($post_type, array $cond) {
        global $wpdb;
        list($dw, $params) = self::date_where($cond);
        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s" . $dw;
        $args = array_merge(array($post_type), $params);
        return (int) $wpdb->get_var($wpdb->prepare($sql, $args)); // phpcs:ignore WordPress.DB
    }

    /**
     * 条件に一致するID（LIMIT付き・全ステータス）。
     */
    private static function ids_type($post_type, array $cond, $limit) {
        global $wpdb;
        list($dw, $params) = self::date_where($cond);
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s" . $dw . " ORDER BY ID ASC LIMIT %d";
        $args = array_merge(array($post_type), $params, array((int) $limit));
        return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $args))); // phpcs:ignore WordPress.DB
    }

    private static function cond_label(array $cond) {
        $names = array();
        foreach ($cond['targets'] as $t) {
            $names[] = self::POST_TYPES[$t];
        }
        $tgt = $names ? implode('・', $names) : '(なし)';
        if ('before' === $cond['mode'] && '' !== $cond['before']) {
            return $tgt . ' / ' . $cond['before'] . ' 以前';
        }
        if ('olderthan' === $cond['mode'] && $cond['days'] > 0) {
            return $tgt . ' / ' . $cond['days'] . '日より古い';
        }
        return $tgt . ' / 全期間';
    }

    // ====================================================================
    // Ajax
    // ====================================================================

    private static function guard() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '権限がありません。'), 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');
    }

    public static function ajax_preview() {
        self::guard();
        $cond = self::parse_condition();
        $counts = array();
        $total = 0;
        foreach ($cond['targets'] as $t) {
            $c = self::count_type($t, $cond);
            $counts[$t] = $c;
            $total += $c;
        }
        wp_send_json_success(array(
            'counts' => $counts,
            'total'  => $total,
            'label'  => self::cond_label($cond),
        ));
    }

    public static function ajax_delete() {
        self::guard();
        @set_time_limit(0); // phpcs:ignore
        $cond = self::parse_condition();
        if (empty($cond['targets'])) {
            wp_send_json_error(array('message' => '対象が選択されていません。'));
        }
        $deleted = 0;
        // バッチ予算 BATCH 件を、対象タイプ順に消化。
        foreach ($cond['targets'] as $t) {
            if ($deleted >= self::BATCH) {
                break;
            }
            $ids = self::ids_type($t, $cond, self::BATCH - $deleted);
            foreach ($ids as $id) {
                // 完全削除（ゴミ箱を経由しない）。成功時のみ加算＝万一削除できない行での無限ループを防ぐ。
                if (wp_delete_post($id, true)) {
                    $deleted++;
                }
            }
        }
        // 残件（再カウント）。
        $remaining = 0;
        foreach ($cond['targets'] as $t) {
            $remaining += self::count_type($t, $cond);
        }
        wp_send_json_success(array('deleted' => $deleted, 'remaining' => $remaining));
    }

    public static function ajax_log() {
        self::guard();
        $cond    = self::parse_condition();
        $total   = isset($_POST['total_deleted']) ? absint(wp_unslash($_POST['total_deleted'])) : 0; // phpcs:ignore
        $user    = wp_get_current_user();
        $entry   = array(
            'time'    => current_time('mysql'),
            'user'    => $user ? $user->user_login : '',
            'cond'    => self::cond_label($cond),
            'deleted' => $total,
        );
        $log = get_option(self::OPT_LOG, array());
        if (!is_array($log)) {
            $log = array();
        }
        array_unshift($log, $entry);
        $log = array_slice($log, 0, self::LOG_MAX);
        update_option(self::OPT_LOG, $log, false);
        wp_send_json_success(array('logged' => true));
    }

    // ====================================================================
    // 画面
    // ====================================================================

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $flamingo = post_type_exists('flamingo_inbound') || post_type_exists('flamingo_contact');
        $nonce    = wp_create_nonce(self::NONCE);
        $log      = get_option(self::OPT_LOG, array());
        if (!is_array($log)) {
            $log = array();
        }
        ?>
        <div class="wrap">
            <h1>Flamingo データ一括削除</h1>
            <p>Flamingo が蓄積した<strong>受信メッセージ</strong>と<strong>アドレス帳</strong>を、日付条件で<strong style="color:#b32d2e;">完全削除</strong>します（ゴミ箱を経由しません＝取り消し不可）。ゴミ箱・スパムを含む全ステータスが対象です。</p>
            <?php if (!$flamingo) : ?>
                <div class="notice notice-warning"><p>このサイトで Flamingo の投稿タイプが見つかりません。Flamingo が有効か確認してください（機能自体は動作します）。</p></div>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">削除対象</th>
                    <td>
                        <label><input type="checkbox" class="wpsg-fl-target" value="flamingo_inbound" checked> 受信メッセージ（flamingo_inbound）</label><br>
                        <label><input type="checkbox" class="wpsg-fl-target" value="flamingo_contact" checked> アドレス帳（flamingo_contact）</label>
                        <p class="description" style="color:#8a6d00;">⚠️ アドレス帳の日付は<strong>「連絡先の作成日」</strong>基準です（最終問い合わせ日ではありません）。古くから登録され最近も問い合わせている連絡先も、作成日が条件に合えば削除されます。受信メッセージだけ消したい場合はアドレス帳のチェックを外してください。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">期間</th>
                    <td>
                        <label><input type="radio" name="wpsg_fl_mode" value="all" checked> 全期間</label><br>
                        <label><input type="radio" name="wpsg_fl_mode" value="before"> 指定日<strong>以前</strong>：</label>
                        <input type="date" id="wpsg-fl-before"><br>
                        <label><input type="radio" name="wpsg_fl_mode" value="olderthan"> <input type="number" id="wpsg-fl-days" min="1" step="1" value="365" style="width:90px;"> 日より古いもの</label>
                        <p class="description">判定基準は <code>post_date</code> です。</p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" class="button" id="wpsg-fl-preview">削除件数をプレビュー</button>
                <button type="button" class="button button-primary" id="wpsg-fl-run" disabled>削除を実行</button>
                <button type="button" class="button" id="wpsg-fl-stop" disabled>中断</button>
            </p>

            <div id="wpsg-fl-status" style="margin:10px 0;padding:10px 12px;border:1px solid #dcdcde;border-radius:6px;background:#fff;min-height:24px;">
                プレビュー未実行。
            </div>

            <h2>実行ログ（直近 <?php echo (int) self::LOG_MAX; ?> 件）</h2>
            <table class="widefat striped" id="wpsg-fl-log">
                <thead><tr><th>実行日時</th><th>実行者</th><th>条件</th><th>削除件数</th></tr></thead>
                <tbody>
                <?php if (empty($log)) : ?>
                    <tr><td colspan="4">まだ実行ログはありません。</td></tr>
                <?php else : foreach ($log as $e) : ?>
                    <tr>
                        <td><?php echo esc_html($e['time'] ?? ''); ?></td>
                        <td><?php echo esc_html($e['user'] ?? ''); ?></td>
                        <td><?php echo esc_html($e['cond'] ?? ''); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) ($e['deleted'] ?? 0))); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <script>
        (function($){
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var running = false, stopFlag = false, totalDeleted = 0;
            var $status = $('#wpsg-fl-status'), $run = $('#wpsg-fl-run'), $stop = $('#wpsg-fl-stop'), $preview = $('#wpsg-fl-preview');

            function targets(){
                var t = [];
                $('.wpsg-fl-target:checked').each(function(){ t.push($(this).val()); });
                return t;
            }
            function cond(){
                return {
                    action_targets: targets(),
                    date_mode: $('input[name=wpsg_fl_mode]:checked').val(),
                    date_before: $('#wpsg-fl-before').val(),
                    date_days: $('#wpsg-fl-days').val()
                };
            }
            function post(action, extra){
                var c = cond();
                var data = $.extend({ action: action, nonce: nonce, date_mode: c.date_mode, date_before: c.date_before, date_days: c.date_days }, extra || {});
                data['targets'] = c.action_targets;
                return $.post(ajaxurl, data);
            }
            function setBusy(b){
                running = b;
                $preview.prop('disabled', b);
                $run.prop('disabled', b || totalPreview <= 0);
                $stop.prop('disabled', !b);
                $('.wpsg-fl-target, input[name=wpsg_fl_mode], #wpsg-fl-before, #wpsg-fl-days').prop('disabled', b);
            }
            var totalPreview = 0;

            $preview.on('click', function(){
                if (targets().length === 0){ $status.text('削除対象を1つ以上選んでください。'); return; }
                $status.text('件数を集計中…');
                post('wpsg_flamingo_preview').done(function(res){
                    if(!res || !res.success){ $status.text('プレビューに失敗しました。'); return; }
                    totalPreview = res.data.total;
                    var lines = [];
                    var names = {flamingo_inbound:'受信メッセージ', flamingo_contact:'アドレス帳'};
                    $.each(res.data.counts, function(k,v){ lines.push(names[k] + '：' + v.toLocaleString() + '件'); });
                    $status.html('<strong>条件：</strong>' + res.data.label + '<br>' + lines.join(' ／ ') + '<br><strong>合計 ' + totalPreview.toLocaleString() + ' 件</strong>を削除します。');
                    $run.prop('disabled', totalPreview <= 0);
                }).fail(function(){ $status.text('通信に失敗しました。'); });
            });

            function loop(){
                if (stopFlag){ finish('中断しました。'); return; }
                post('wpsg_flamingo_delete').done(function(res){
                    if(!res || !res.success){ finish('削除中にエラーが発生しました。' + (res && res.data && res.data.message ? ' '+res.data.message : '')); return; }
                    totalDeleted += res.data.deleted;
                    var remaining = res.data.remaining;
                    $status.html('削除中… <strong>' + totalDeleted.toLocaleString() + '</strong> 件処理済み ／ 残り <strong>' + remaining.toLocaleString() + '</strong> 件');
                    if (remaining > 0 && res.data.deleted > 0 && !stopFlag){
                        loop();
                    } else {
                        finish(remaining > 0 ? ('停止：残り ' + remaining.toLocaleString() + ' 件') : '完了しました。');
                    }
                }).fail(function(){ finish('通信に失敗しました（時間をおいて再実行で続きから処理できます）。'); });
            }

            function finish(msg){
                // ログ記録（完了・中断いずれも記録）。
                post('wpsg_flamingo_log', { total_deleted: totalDeleted }).always(function(){
                    $status.html('<strong>' + msg + '</strong>（このセッションで ' + totalDeleted.toLocaleString() + ' 件削除）');
                    setBusy(false);
                    // ログ表示を更新するためリロード促し。
                    if (totalDeleted > 0){ $status.append(' <a href="">ログを更新</a>'); }
                });
            }

            $run.on('click', function(){
                if (running) return;
                if (targets().length === 0){ $status.text('削除対象を選んでください。'); return; }
                if (totalPreview <= 0){ $status.text('先にプレビューしてください。'); return; }
                if (!window.confirm('合計 ' + totalPreview.toLocaleString() + ' 件を完全削除します。\nこの操作は取り消せません。実行しますか？')) return;
                totalDeleted = 0; stopFlag = false;
                setBusy(true);
                loop();
            });
            $stop.on('click', function(){ stopFlag = true; $stop.prop('disabled', true); });
        })(jQuery);
        </script>
        <?php
    }
}
