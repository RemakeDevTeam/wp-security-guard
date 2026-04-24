# 変更履歴

本ファイルはプラグインのすべてのバージョン変更を記録します。
形式は [Keep a Changelog](https://keepachangelog.com/ja/1.1.0/) に準拠し、
バージョニングは [Semantic Versioning](https://semver.org/lang/ja/) に従います。

---

## [2.0.0] - 2026-04-25

### 統合・刷新版リリース

#### Added(追加)
- ユーザー名列挙対策機能
  - REST APIユーザー情報保護(`/wp-json/wp/v2/users`)
  - `?author=N` リクエスト遮断
  - ログインエラーメッセージ統一
- WordPressバージョン情報隠蔽機能
  - `<meta name="generator">` の削除
  - RSSフィードのgeneratorタグ除去
  - CSS/JSの `?ver=X.X.X` クエリ削除
- アプリケーションパスワード無効化機能
- CF7スパム対策の強化
  - 最小日本語文字数チェック
  - URL数上限チェック

#### Changed(変更)
- 管理画面を「セキュリティガード」メニューに統一
- 各機能を個別にON/OFF可能なUIに再構成
- XML-RPC遮断を `.htaccess` からプラグイン側に移行(Apache/Nginx問わず動作)

#### Integrated(統合)
- 旧プラグイン `spam-guard-cf7` の全機能を統合
  - 設定値・動作は完全継承(`message_field`, `check_sender`, `sender_field`)
  - エラーメッセージ「英語でのお問い合わせには対応しておりません」を踏襲

---

## [1.0.0] - 2026-04-25

### 初版リリース(中間版)

#### Added
- `.htaccess` のXML-RPC遮断記述をプラグイン側に統合
- 旧 `spam-guard-cf7` プラグインを統合(日本語チェック機能)
- 基本的な管理画面

---

## 旧 spam-guard-cf7 プラグイン(本プラグインの前身)

### 機能
- Contact Form 7 の本文フィールドに日本語(ひらがな/カタカナ/漢字)が含まれない投稿をブロック
- 差出人名フィールドのチェック機能(オプション)
- フィールドIDのカスタマイズ機能
