# 変更履歴

本ファイルはプラグインのすべてのバージョン変更を記録します。

## 2.8.0

- **Flamingo データ一括削除機能を新設**（`WPSG_Flamingo_Cleanup`／「セキュリティガード → Flamingo削除」）。Flamingo が蓄積した**受信メッセージ(flamingo_inbound)**と**アドレス帳(flamingo_contact)**を、日付条件で**完全削除**（`wp_delete_post($id, true)`＝ゴミ箱を経由しない）。スパム流入の入口対策ではなく、蓄積データの掃除用。
- **対象は個別選択**（受信メッセージ／アドレス帳のチェックボックス・既定は両方）。**全ステータス対象**（trash/spam 含む）＝post_status を非フィルタで抽出するため取りこぼしなし。
- **期間指定**：全期間／指定日以前（日付ピッカー）／N日より古い（日数）。判定は `post_date`。
- **Ajaxバッチ処理**（250件/回・JSループ）：進捗表示（処理済み／残件）、**中断ボタン**、中断・失敗しても**再実行で続きから**（各バッチが現在の一致IDを再取得するためレジューム可能）。実行前に**削除予定件数をプレビュー**。
- **安全策**：`manage_options` 権限＋nonce、実行前の**確認ダイアログ**（件数明示・取り消し不可を表示）、**実行ログ**を option `wpsg_flamingo_log` に直近20件保存（実行日時・実行者・条件・削除件数）。
- ⚠️ **アドレス帳の日付は「連絡先の作成日」基準**（最終問い合わせ日ではない）。古くから登録され最近も問い合わせている連絡先も、作成日が条件に合えば削除される旨を画面に明示。最終受信日基準が必要になれば inbound からの逆引きで拡張可能。

## 2.7.0

- **Stripe点検の「モード概要」を既定/共通トークンでも取得可能に**（`/wpsg/v1/inspect/stripe`）。集中管理側が **wp-config への `WPSG_INSPECT_TOKEN` 追加や個別トークン発行なし**で、全サイトの **テスト/本番モード（test/live）** を収集できるようにした（プラグイン自動更新で全サイトへ配布＝サイト個別作業ゼロ）。
- **機微詳細は従来どおり個別(explicit)トークン限定**：既定/共通トークンでの `/stripe` は `redact_stripe_to_mode()` で **モード概要（test/live/configured の件数・実装名・モード一貫性）だけ**に絞り、**鍵の設定有無・webhook・通貨・実装詳細は返さない**。個別トークンで叩けば従来どおり全項目。
- ゲート変更：既定トークンの機微ブロック対象を `/membership` `/all` のみに（`/stripe` は上記redactで安全に開放）。`route_stripe` はリクエストのトークン分類（`token_class()`）に応じて全項目/概要を出し分け。
- 目的：本番リリースしたのに Stripe がテストモードのまま、を集中管理側で継続監視できるようにする（決済実装＝WP Full Pay / WooCommerce の両方に対応）。

## 2.6.0

- **会員登録スパム対策モジュール（セクション6）を新設**。WordPress標準（`wp-login.php?action=register`）と **WooCommerce のマイアカウント登録フォーム**の両方に適用。ECMサイトで発生した「登録メール中継目的のスパム会員登録（約950通が外部配送）」への恒久対策。
- **JSトークン検証**（最重要）：フォームにJSで書き込むワンタイムトークンを検証し、JavaScriptを実行しないスクリプト型POSTボットをほぼ完全に遮断。当日/前日分を許可しUTC日付境界に対応。
- **多層防御**：ハニーポット（画面外の隠し欄）／**署名付き時間トラップ**（表示から最小秒数未満を拒否・キャッシュ誤検知回避のため下限のみ）／**ユーザー名の内容検証**（URL・不正TLDドメイン・スパム定型句・40字超を拒否）／**IP単位のレート制限**（トランジェント・IPはハッシュ化し平文非保存）。
- **`wp-login.php?action=register` の遮断**：`login_init` でGET/POST双方をトップへリダイレクト。**`users_can_register` の値に依存せず**遮断するため、WordPress標準設定を「登録可」に戻しても防御が維持される。
- **メール抑制オプション**（既定OFF）：登録者宛「ログインの詳細」メール／管理者宛「新規ユーザー登録」通知を停止可能（登録者宛は正規顧客がパスワード設定メールを受け取れなくなる副作用を管理画面に明記）。
- **拡張フィルタ**：`wpsg_registration_spam_keywords`（キーワード上書き）／`wpsg_registration_looks_like_spam`（判定上書き）／`wpsg_client_ip`（Cloudflare等でのIP差し替え）／`wpsg_registration_pre_validate`（外部CAPTCHA差し込み口）。
- マスタースイッチ `enable_registration_guard`（既定ON）で全体を一括ON/OFF。管理画面でマスターOFF時は残り8項目をJSで非活性化。全9キーを `sanitize_options()` に登録（保存漏れなし）。
- ※ セクション番号：点検モジュールの旧「6/7」は別描画（`wpsg_admin_page_extension_render`）かつ v2.5.2 で既定非表示のため、設定フォーム上の番号衝突なし。

## 2.5.3

- **プラグイン一覧にシステム略語ラベルを表示**（`WPSG_Plugin_Labeler`）。管理画面「プラグイン」でプラグイン名の後ろに `【MS・OS】` 等を薄字で表示し、どのシステム用かを可視化（名称変更は不要）。
- 判定は **`System:` プラグインヘッダー優先 → 同梱の slug→システム略語マップ**でフォールバック。third-party/インフラは対象外。
- 略語は remakemanager / GitHub と統一（MS/OS/LC/ECM/CRMS/LCF）。フォルダ名の版/old/main 揺れは正規化して照合。
- マップは `wpsg_plugin_system_map` フィルタで拡張・上書き可能。

## 2.5.2

- **管理画面の「6. サイト点検設定」「7. サイト機能フラグ管理」を既定で非表示**に変更。点検・プラグイン版取得は集中管理側（remakemanager）で行うため、各サイトの管理UIは不要。
- **REST 点検エンドポイントは維持**（`/wpsg/v1/inspect/*`）。同梱の既定トークンでの点検は従来どおり動作するため、集中管理側の診断に影響なし。
- トークン発行/許可IP設定/機能フラグ保存の各UI・保存処理は読み込まない（`WPSG_Admin_Page_Extension` を初期化しない）。
- 再表示が必要になった場合は `add_filter('wpsg_enable_inspector_admin_ui', '__return_true');` の1行で復帰可能（機能自体は残置）。

## 2.5.1

- **同梱の既定Inspectorトークン**を追加（複数サイト一括運用向け）。サイト側で個別トークン（`WPSG_INSPECT_TOKEN` 定数 or 管理画面発行）を一切設定していなくても、集中管理側から診断できる。→ **各サイトを開いてトークンを設定する初回作業が不要**（v2.5.0 の自動更新で配布するだけ）。
- **既定トークンは低リスク項目のみ許可**：`core` / `theme` / `plugins` / `features` / `health`。`membership` / `stripe` / `all`（機微情報）は従来どおり**個別トークン必須**（`token_scope` 403）。
- 高機微サイトで既定トークンを無効化するには wp-config で `define('WPSG_DISABLE_DEFAULT_INSPECT_TOKEN', true);`（個別トークンのみ有効化）。
- トークン検証を `verify()` から `classify()`（`explicit` / `default` / `false`）へ整理。REST 層が分類に応じてエンドポイントを絞る。
- 注意：この既定トークンは配布物に含まれるため公開情報です。露出するのはテーマ/プラグイン等の**バージョン・構成メタのみ**（機微APIは対象外）。IP許可リストの併用と、必要なら個別トークン運用を推奨。

## 2.5.0

- 自己ホスト更新（plugin-update-checker v5.7 同梱・GitHub更新元）。既定でこのプラグインの自動更新をON（`WPSG_DISABLE_AUTO_UPDATE` で無効化可）。更新元は `WPSG_UPDATE_REPO` / 認証 `WPSG_UPDATE_TOKEN` / ブランチ `WPSG_UPDATE_BRANCH` で上書き可。
- Inspectorトークンの共通トークン対応：`define('WPSG_INSPECT_TOKEN', '…')`（wp-config）で全サイト共通トークンを受け付け（`verify()`／`is_set()`）。サイトごとのトークン発行なしで一括運用が可能に。IP許可リストと併用推奨。

形式は [Keep a Changelog](https://keepachangelog.com/ja/1.1.0/) に準拠し、
バージョニングは [Semantic Versioning](https://semver.org/lang/ja/) に従います。

---

## [2.4.3] - 2026-05-01

### トークン平文表示問題の真の修正 (緊急)

#### Fixed(修正)
- **「新規トークン生成」「トークンを再生成」を実行しても、平文トークンが画面上に表示されない問題を真の解決**
  - 症状: トークン生成は成功し、生成された平文トークンの DOM 要素も存在するが、画面上に**サイズゼロ (`display: none`) で表示されない**
  - 真の原因: `<div class="notice notice-info">` クラスを使っていたため、**WordPress 標準の admin notice 管理機構や、他プラグインの notice 操作スクリプト**が「これは admin notice」として認識し、移動・hide 等の処理を施していた
  - 修正: ビューファイル内の **すべての `notice notice-*` クラスを独自クラス (`wpsg-status-box`, `wpsg-flash-token-box`)** に置き換え、インラインスタイルで同等の見た目を再現
  - これにより、**80サイト展開時に各サイトのプラグイン環境に依存しない**安定動作を保証

#### Changed(変更)
- ビューファイル `inspector-settings.php`:
  - `notice notice-success is-dismissible` → `wpsg-status-box wpsg-status-success`
  - `notice notice-warning is-dismissible` → `wpsg-status-box wpsg-status-warning`
  - `notice notice-info` → `wpsg-flash-token-box`
- ビューファイル `features-editor.php`:
  - `notice notice-success is-dismissible` → `wpsg-status-box wpsg-status-success`
  - `notice notice-info is-dismissible` → `wpsg-status-box wpsg-status-info`
  - `notice notice-warning` → `wpsg-status-box wpsg-status-warning`
- 拡張クラス `class-admin-page-extension.php`:
  - フォールバック警告 `notice notice-warning` → `wpsg-status-box wpsg-status-warning`
- スタイルはすべて**インラインで明示**(他プラグインの CSS 干渉を避けるため)
  - 成功通知: 緑系 (`#f0f9f0` / `#00a32a`)
  - 警告通知: 黄系 (`#fff8e5` / `#dba617`)
  - 情報通知: 青系 (`#e8f4fd` / `#2271b1`)
  - 平文トークン表示: 強調黄色 (`#fef3c7` / `#f59e0b` 太枠)
- バージョンを 2.4.2 → 2.4.3

#### Note(80サイト展開への影響)
- 各サイトには様々なプラグインがインストールされており、それぞれが管理画面の `.notice` 要素を独自に操作している可能性がある
- 本修正により、どんなプラグイン環境でも一貫したトークン表示が保証される
- ただし `is-dismissible` (右上のXボタン) は使えなくなった (admin notice 専用機能のため)
  - 通知は次のページ遷移で消えるので、運用上の問題はない

#### 既存トークンへの影響
- なし。トークン値そのものは変更されない
- ただし、v2.4.2 以前で「平文を保存できなかった」トークンは取り戻せないので、再生成が必要

---

## [2.4.2] - 2026-05-01

### トークン平文表示問題の修正 (緊急)

#### Fixed(修正)
- **「新規トークン生成」「トークンを再生成」を実行した直後に、平文トークンが画面に表示されない問題を修正**
  - 症状: トークン生成は成功し「✓ 設定済み」になるが、生成画面で64文字の平文トークンが表示されない
  - 原因: `set_transient` → `wp_redirect` → `get_transient` の流れで、オブジェクトキャッシュ (Redis/Memcached) 環境では transient が消失するケースがあった
  - 修正: `set_transient`/`get_transient` から `update_user_meta`/`get_user_meta` への切り替え。User Meta は `wp_usermeta` テーブルへの直接書き込みなので、キャッシュ整合性問題の影響を受けにくい

#### Changed(変更)
- 平文トークンの保持期間を 60秒 → 300秒 に延長
  - User Meta は明示削除しない限り永続するため、表示忘れ防止のため期限切れチェックを追加
  - 取得時に期限を確認し、期限切れなら表示せず削除のみ
- バージョンを 2.4.1 → 2.4.2

#### Added(追加)
- ヘルパーメソッド `WPSG_Admin_Page_Extension::consume_flash_token($user_id)`
  - User Meta から平文トークンを取り出して即削除する処理を集約
  - 取得後の自動削除により「1度だけ表示」保証を維持
- v2.4.1以前との後方互換コード
  - 旧 transient ベースの値が残っている場合は、それも消費して優先表示

#### Note(運用への影響)
- **既存のトークンは引き続き有効です** (本修正はトークン値そのものでなく、平文の一時表示方法のみ変更)
- v2.4.1 環境ですでに `✓ 設定済み` だが平文を保存できなかった場合は、もう一度「トークンを再生成」してください

#### 影響を受けるファイル
- `wp-security-guard.php` (バージョン)
- `README.md` (バージョン)
- `CHANGELOG.md` (本エントリ)
- `includes/admin/class-admin-page-extension.php` (transient → user_meta 切り替え + ヘルパー追加)

---

## [2.4.1] - 2026-05-01

### 管理画面レイアウト崩れ修正 (恒久対応)

#### Fixed(修正)
- **管理画面の「6. サイト点検設定」「7. サイト機能フラグ管理」セクションが左サイドバー(160px)の後ろに潜り込むレイアウト崩れを恒久修正**
  - 原因: `admin_footer-{hookname}` フックで `<div class="wrap">` を出力していたため、セクションが `#wpcontent` の外側 (`#wpwrap` 直下) に配置されていた
  - 修正: メインプラグインの `render_admin_page()` 内で `do_action('wpsg_admin_page_extension_render')` を発火し、`<div class="wrap">` の内部で点検セクションを描画するように変更
  - 影響範囲: `wp-security-guard.php` (メインプラグイン), `class-admin-page-extension.php` (拡張クラス), `inspector-settings.php` / `features-editor.php` (ビューファイル)

#### Added(追加)
- 拡張フック `wpsg_admin_page_extension_render`
  - メインプラグインの設定画面 (`<div class="wrap">...</div>`) 内部・閉じ `</div>` 直前で発火
  - 点検モジュール (Phase 1〜4) はこのフックでセクションを描画する
  - サードパーティプラグインや独自カスタマイズもこのフックを利用可能
- 後方互換フォールバック (`render_legacy_fallback()`)
  - 古いメインプラグイン (v2.4.0以前) と新しい点検モジュールが組み合わされた場合のため
  - `admin_footer` フックで描画した内容をJavaScriptで `#wpcontent > .wrap` 内部に移動
  - フォールバック動作時は管理画面に warning 通知を表示

#### Changed(変更)
- ビューファイル (`inspector-settings.php`, `features-editor.php`) の冒頭の `<div class="wrap">` を `<div class="wpsg-inspector-section">` / `<div class="wpsg-features-section">` に変更
  - 親に `<div class="wrap">` がある前提の構造に整理
  - WordPress標準のwrap CSSが二重適用されないように
- バージョンを 2.4.0 → 2.4.1

#### Note(影響なし)
- REST API (`/wp-json/wpsg/v1/inspect/*`) のレスポンス構造は完全互換
- 点検モジュールのバージョン (`WPSG_INSPECTOR_VERSION`) は 1.3.0 のまま据え置き
- Pythonランナー側に変更なし
- データベース構造に変更なし

#### Verification(動作確認方法)
1. v2.4.1 を有効化後、管理画面 → セキュリティガード を開く
2. 下にスクロールして「6. サイト点検設定」の表が左サイドバーの後ろに隠れていないことを確認
3. 「7. サイト機能フラグ管理」のフォーム要素が画面中央に正しく表示されていることを確認

---

## [2.4.0] - 2026-04-30

### Stripe決済inspector追加(Phase 4)

#### Added(追加)
- Stripe決済設定inspector (`includes/inspectors/stripe/`)
  - 抽象基底クラス `WPSG_Stripe_Inspector_Base`
    - 機微情報マスクヘルパー (`key_prefix()` で先頭8文字のみ返却)
    - 共通レスポンス構造のテンプレート (`build_response()`)
    - 命名ゆらぎ吸収用 `find_value()`
  - 個別inspector
    - `WPSG_Stripe_WC_Gateway` - WooCommerce公式Stripe Gateway
    - `WPSG_Stripe_WC_Vendors_Connect` - WC Vendors Stripe Connect
    - `WPSG_Stripe_WP_Full_Stripe` - Themeisle版WP Full Stripe (オプション名は8候補から探索)
    - `WPSG_Stripe_PayPal_Plugins` - 公式WC PayPal Payments / Payment Plugins for PayPal
    - `WPSG_Stripe_BankPay` - 振込先口座設定の有無のみ確認
  - ディスパッチャー `WPSG_Stripe_Dispatcher`
    - 複数決済実装の同居対応
    - **モード一貫性チェック**(WC StripeはLive、WP Full StripeはTest 等の不一致を検出)
- REST APIエンドポイント追加
  - `/wp-json/wpsg/v1/inspect/stripe` - 決済設定の統合結果
  - `/wp-json/wpsg/v1/inspect/all` を決済設定込みに拡張
- 管理画面のエンドポイント一覧に `/stripe` 追加

#### 機微情報マスク方針(厳格)
- Publishable Key: 先頭8文字のみ (例: `pk_live_xxxxxxxx`)
- Secret Key: 「設定済み/未設定」のboolのみ
- Webhook Secret: 「設定済み/未設定」のboolのみ
- Connect Application ID: 先頭8文字のみ
- BankPay 振込先口座: 設定有無のboolのみ(中身は絶対に返さない)

#### Changed(変更)
- バージョンを 2.3.0 → 2.4.0
- 点検モジュールのバージョンを 1.2.0 → 1.3.0

#### Note(WP Full Stripeのオプション名)
WP Full Stripe (Themeisle版) のStripe鍵保存先オプション名は実機未確認。
8候補のオプション名を順に試行し、見つかったものを `custom.option_name_used` で返す。
WP-CLIで以下のコマンドにより実際のキー名を確認可能:

```bash
wp option list --search="*Stripe*" --format=table
wp option list --search="*fullstripe*" --format=table
wp option list --search="*wpfs*" --format=table
```

#### 既存機能への影響
- なし。Phase 1/2/3 のAPIは変更なし(`/inspect/all` には `stripe` キーが追加されるが互換性保持)

---

## [2.3.0] - 2026-04-30

### 会員管理プラグイン別inspector追加(Phase 3)

#### Added(追加)
- 会員管理プラグイン別inspector (`includes/inspectors/membership/`)
  - 抽象基底クラス `WPSG_Membership_Inspector_Base`
    - 共通インターフェース: `is_active()` / `get_implementation_id()` / `get_products()` / `get_metadata()`
  - 個別inspector
    - `WPSG_Membership_Ultimate_Member` - UMロール情報、UM管理ロール検出、アドオン検出
    - `WPSG_Membership_WP_Full_Stripe` - Themeisle版WP Full Stripeのフォーム/プラン (テーブル直接参照)
      - 6種類のフォームテーブル (subscription/payment/donation × inline/checkout) を横断スキャン
      - decoratedPlans JSON展開
      - 旧版Mammothology製プラグインの残存検出
      - members テーブルのlivemode混在検出
    - `WPSG_Membership_WooCommerce` - WC商品、決済ゲートウェイ、サブスクリプション検出
    - `WPSG_Membership_WC_Vendors` - コミッション率、ベンダー数、Pro/Stripe Connectアドオン検出
    - `WPSG_Membership_WP_Crowdfunding` - プロジェクト・リワード情報
    - `WPSG_Membership_BankPay` - 月額/年額/入会金プラン、会員ステータス統計
      - 振込先口座は機微情報のため設定有無のみ返却
  - ディスパッチャー `WPSG_Membership_Dispatcher`
    - 複数実装の同居対応 (UM + WP Full Stripe + BankPay 等)
    - 拡張用フィルターフック `wpsg_inspect_membership_inspectors`
- REST API エンドポイント追加
  - `/wp-json/wpsg/v1/inspect/membership` - 全会員管理inspectorの統合結果
  - `/wp-json/wpsg/v1/inspect/all` を会員管理込みに拡張
- 管理画面のエンドポイント一覧に `/membership` 追加

#### Changed(変更)
- バージョンを 2.2.0 → 2.3.0
- 点検モジュールのバージョンを 1.1.0 → 1.2.0

#### 設計上のポイント
- 「products」概念で全実装を統一表現 (フォーム/プラン/商品/プロジェクト/ロール)
- WP Full Stripeのプラン情報はREST API非公開のため `$wpdb` 直接参照
- 旧版Mammothology製プラグイン残存を inspectors/plugins と membership/wp-full-stripe の両方で検出
- BankPayは点検プラグイン側に直接実装 (フィルターフック方式から変更、v1.1の仕様書に従う)

#### 既存機能への影響
- なし。Phase 1/2 のAPIは変更なし(`/inspect/all` には新フィールドが追加されるが互換性保持)

---

## [2.2.0] - 2026-04-30

### 機能フラグ管理モジュール追加(Phase 2)

#### Added(追加)
- 機能フラグ管理モジュール
  - 26機能 × 7カテゴリ × 6システム種別の定義マスター (`class-feature-registry.php`)
  - 自動検出器 (`class-feature-detector.php`)
    - プラグインの存在、関数、テーブル、オプション値から各機能を判定
    - 信頼度を high / medium / low / none で返却
    - 拡張用フィルター `wpsg_inspect_detect_feature_{feature_id}` 提供
  - 確定値の保存・取得 (`class-feature-storage.php`)
    - サイト種別、サイトラベル、機能フラグ、備考を `wp_options.wpsg_site_features` に保存
    - 保存値と検出値の乖離計算
- REST API エンドポイント追加
  - `/wp-json/wpsg/v1/inspect/features` - 保存値・検出値・乖離・サマリーを返却
  - `/wp-json/wpsg/v1/inspect/all` を機能フラグ込みに拡張
- 管理画面に「7. サイト機能フラグ管理」セクションを追加
  - システム種別の選択
  - サイトラベルの設定
  - カテゴリ別の機能フラグ編集 (チェックボックス)
  - 各機能の検出結果と信頼度の可視化
  - 「自動検出してプリセット」ボタン
  - 乖離行のハイライト表示
  - 備考メモ

#### Changed(変更)
- バージョンを 2.1.0 → 2.2.0 (機能追加のため)
- 点検モジュールのバージョンを 1.0.0 → 1.1.0
- `/inspect/all` レスポンスに `features` セクション追加 (互換性破壊なし)

#### 既存機能への影響
- なし。Phase 1 の API は変更なし。

---

## [2.1.0] - 2026-04-30

### サイト点検モジュール追加(Phase 1)

#### Added(追加)
- サイト点検モジュール (`includes/` 配下)
  - REST API エンドポイント `/wp-json/wpsg/v1/inspect/*`
    - `/health` - 認証不要の軽量稼働確認
    - `/all` - 全項目一括取得 (認証必要)
    - `/core` - WordPress / PHP / MySQL 情報 (認証必要)
    - `/theme` - 有効テーマ・親テーマ・更新有無 (認証必要)
    - `/plugins` - 全プラグイン一覧 + 旧版残存検出 (認証必要)
  - Bearer トークン認証 (ハッシュ化保存)
  - レート制限 (1分60リクエスト/IP)
  - 許可IP制限 (任意設定)
  - アクセスログ (最終アクセス日時・IP)
- 管理画面に「6. サイト点検設定」セクションを追加
  - トークン生成・再生成・削除
  - 許可IPリスト管理
  - エンドポイント一覧表示
  - curl 動作確認コマンド表示

#### Changed(変更)
- バージョンを 2.0.0 → 2.1.0 (機能追加のため マイナーバージョンアップ)

#### 既存機能への影響
- なし。既存のセキュリティ機能 (XML-RPC遮断・ユーザー名列挙対策等) は変更なし。
- 点検モジュールは独立したクラスとして読み込まれ、既存の `WPSecurityGuard` クラスには一切手を加えていない。

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
