<?php
/**
 * WP Security Guard - Inspector Base Class
 *
 * すべての個別inspectorが継承する基底クラス。
 * 共通のwarnings収集機構と、サブクラスが実装すべきinspect()を定義する。
 *
 * @package WPSecurityGuard
 * @subpackage SiteInspector
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class WPSG_Inspector_Base {

    /**
     * 警告メッセージ配列
     * @var array
     */
    protected $warnings = array();

    /**
     * 点検処理を実行し、結果データを返す
     *
     * @return array
     */
    abstract public function inspect();

    /**
     * 蓄積された警告を取得する
     *
     * @return array
     */
    public function get_warnings() {
        return $this->warnings;
    }

    /**
     * 警告を追加する
     *
     * @param string $level   warning|error|notice
     * @param string $code    短い識別子(例: 'legacy_plugin_residual')
     * @param string $message 人間向けメッセージ
     * @param array  $details 補足情報
     */
    protected function add_warning($level, $code, $message, $details = array()) {
        $this->warnings[] = array(
            'level'   => $level,
            'code'    => $code,
            'message' => $message,
            'details' => $details,
        );
    }

    /**
     * グローバル$wpdbへの安全なアクセス
     *
     * @return wpdb
     */
    protected function wpdb() {
        global $wpdb;
        return $wpdb;
    }

    /**
     * テーブルの存在確認
     *
     * @param string $table_name 完全なテーブル名(プレフィックス込み)
     * @return bool
     */
    protected function table_exists($table_name) {
        $wpdb = $this->wpdb();
        // SHOW TABLES LIKE はLIKE展開を行うが、テーブル名の % や _ は通常含まれない
        $result = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ));
        return $result === $table_name;
    }
}
