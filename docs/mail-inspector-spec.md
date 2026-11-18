# WPSG「メール送信設定」inspector 実装仕様（別スタッフ引き継ぎ）

対象リポジトリ: `wp-security-guard`（現 Version 2.10.0）
作成: 2026-11-18 / 調査は実コード（file:line付き）＋対象プラグインの一次ソース（Web裏取り）＋WPSMの既存点検データ（フリート実測）に基づく。

## 0. 目的・スコープ

WordPress点検（WPSG）で、各サイトの次の3項目を自動収集できるようにする。

1. **Easy WP SMTP の SMTP 設定**
2. **MailPoet の SMTP 設定**
3. **MailPoet「設定＞高度な設定＞すべてのサイトのメールを送信…」が「デフォルトの WordPress 送信方法（デフォルト）」になっているか**

**今回のスコープ＝WPSG（収集側）のみ。** 受け取り・表示するWPSM（remakemanager）側の改修は別担当・今回対象外。WPSMは既存の `GET /wpsg/v1/inspect/all` を叩くだけで、レスポンスに増える新キー `data.mail` を受け取れる（WPSGは pull・push無し）。

## 1. 取得する3項目と出力設計（`data.mail`）

inspector は機微を伏せた要約だけを返す。提案構造（各実装が `details[impl_id]` に格納）:

```
data.mail = {
  implementations: ["easy-wp-smtp", "mailpoet"],   // 検出できた実装
  baseline_mailer: "wp_default",                    // どれも送信を握っていない時のベースライン明示
  details: {
    "easy-wp-smtp": {
      variant: "v2",            // "v2" | "legacy"（系統。下記3-1/3-2）
      version: "2.15.1",
      mailer: "smtp",           // mail.mailer
      host: "...", port: 587, encryption: "tls", auth: true, user: "...",
      from_email: "...", from_name: "...",
      password_set: true        // ★真偽のみ。値・接頭辞・復号は一切返さない
    },
    "mailpoet": {
      version: "5.45.2",
      newsletter_method: "SMTP", // mta.method（MailPoetがニュースレターを送る方式）
      host: "...", port: 465, encryption: "ssl", auth: true, login: "...",
      password_set: true,        // ★真偽のみ（MailPoetはDB平文なので特に厳守）
      has_mailpoet_api_key: false,
      // ↓ 第3問の答え。mta とは別設定。
      default_wp_mailer: true    // true＝「デフォルトのWordPress送信方法」/ false＝MailPoetが全wp_mailを肩代わり
    }
  },
  warnings: [ /* 競合・未設定などの notice */ ]
}
```

> **第3問の回答そのもの** = `details.mailpoet.default_wp_mailer`。`true` なら「デフォルトのWordPress送信方法（デフォルト）」。

## 2. フリート実態（WPSMの既存点検データ `_wpsm_inspect_plugins` 実測・2026-11-18）

点検済み68サイト中:

- **Easy WP SMTP = 68サイト全て slug `easy-wp-smtp`（＝v2系・Awesome Motive版）。** 版は 2.7.0〜2.15.1（大多数 2.15.1）。**旧系（slug/option `swpsmtp_options`）は0件。**
  → **実装は v2 option `easy_wp_smtp` を主系統とする。** 旧系分岐は防御的に持つだけでよい（フリートには存在しない）。
- **MailPoet = 65サイトに導入**（active/inactive 混在）。版 5.29.0〜5.45.2。1サイトに旧 `_mailpoet` v3.85.0 の残骸(inactive)。
  → 専用テーブル `{prefix}mailpoet_settings` 方式で全世代共通。

## 3. 各設定の格納場所と読み取り式（確定・一次ソース裏取り済み）

### 3-1. Easy WP SMTP v2（主系統・option `easy_wp_smtp`）

`\EasyWPSMTP\Options::META_KEY='easy_wp_smtp'`。PHP serialize 配列（`get_option()` が自動 unserialize）。

| 項目 | キー | 備考 |
|---|---|---|
| メーラー | `['mail']['mailer']` | 'smtp' 等 |
| 差出人 | `['mail']['from_email']` / `['from_name']` | 平文 |
| ホスト | `['smtp']['host']` | 平文 |
| ポート | `['smtp']['port']` | 平文 |
| 暗号化 | `['smtp']['encryption']` | 'none'\|'ssl'\|'tls' |
| Auto TLS | `['smtp']['autotls']` | bool |
| 認証 | `['smtp']['auth']` | bool |
| ユーザー名 | `['smtp']['user']` | 平文 |
| パスワード | `['smtp']['pass']` | **Crypto暗号化。定数 `EASY_WP_SMTP_SMTP_PASS` で上書きされる場合あり。復号しない** |

```php
$o = get_option('easy_wp_smtp', array());
$s = isset($o['smtp']) ? $o['smtp'] : array();
$mailer = isset($o['mail']['mailer']) ? $o['mail']['mailer'] : null;
$host=$s['host']??null; $port=$s['port']??null; $enc=$s['encryption']??null;
$auth=!empty($s['auth']); $user=$s['user']??null;
$pass_set = (!empty($s['pass'])) || defined('EASY_WP_SMTP_SMTP_PASS'); // ← 値は出さない
```

### 3-2. Easy WP SMTP 旧系（防御的・option `swpsmtp_options`／フリートには無し）

版 < 2.0 のみ。`['smtp_settings']['host'|'type_encryption'|'port'|'autentication'(値'yes'/'no'・綴りtypo)|'username'|'password']`、差出人は `['from_email_field']`/`['from_name_field']`。パスワードは既定 base64、暗号化ONで openssl/AES（別 option `swpsmtp_pass_encrypted` が bool フラグ）。**base64/復号ともにデコードしない。**

### 3-3. 系統の見分け方（本体ファイルパスは両系統同一なので版で判定）

両系統とも `easy-wp-smtp/easy-wp-smtp.php`。`is_plugin_active()` では区別不可。
**判定: `get_plugin_version() >= 2.0.0` または `class_exists('\\EasyWPSMTP\\Core')` なら v2 → `easy_wp_smtp` を読む。** `< 2.0` のときだけ旧系 `swpsmtp_options`。移行後に両 option が併存し得るので「v2優先」。出力 `variant` に `v2|legacy` を残す。

### 3-4. MailPoet SMTP（テーブル `{prefix}mailpoet_settings` の `mta` 行）

物理テーブル名 `{$wpdb->prefix}mailpoet_settings`（name/value 行）。**値は配列=PHP serialize / スカラー=生保存（JSONではない）。** `mta` は配列なので serialize。

`mta`（unserialize後）: `method`（'MailPoet'|'SMTP'|'PHPMail'|'AmazonSES'|'SendGrid'／SMTP設定として意味を持つのは `'SMTP'` のとき）、`host`、`port`、`login`（＝ユーザー名。'username'ではない）、`password`（**DB内は平文**）、`encryption`（''|'ssl'|'tls'）、`authentication`（(int)キャスト）。同配列に `mailpoet_api_key` も入り得る。

```php
$t = $wpdb->prefix . 'mailpoet_settings';
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t)) === $t) {
  $mta = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT value FROM {$t} WHERE name=%s",'mta')));
  if (is_array($mta) && ($mta['method']??null)==='SMTP') {
    $host=$mta['host']??null; $port=$mta['port']??null; $enc=$mta['encryption']??null;
    $auth=(int)($mta['authentication']??0); $login=$mta['login']??null;
    $pass_set = !empty($mta['password']);                 // ← 値は絶対に出さない（平文のため特に厳守）
  }
}
```

### 3-5. 第3問＝「すべてのサイトのメールを送信…＝デフォルトWP送信方法か」

同テーブルの **`name='send_transactional_emails'` の1行**（スカラー・serializeされない）。MailPoet の `WordpressMailerReplacer` が `$this->settings->get('send_transactional_emails', false)` を truthy 判定し、真なら MailPoet が全 wp_mail を差し替える。

```php
$raw = $wpdb->get_var($wpdb->prepare("SELECT value FROM {$t} WHERE name=%s",'send_transactional_emails'));
$default_wp_mailer = empty($raw);   // 行なし/''/'0' → true（デフォルトWP）／ '1' → false（MailPoetが肩代わり）
```

- 既定（第2引数 false）＝**未設定なら「デフォルトWP」**。
- **`mta`（ニュースレター送信方式）とは別設定。** `mta` が SMTP でも `default_wp_mailer=true` なら通常の wp_mail（他のSMTPプラグイン等）は素通り。出力でも両者を分離すること。

## 4. マスク方針（機微・既存の社内標準＝Stripe/WPFSライセンスと同じ）

- **パスワードは `password_set`(bool) のみ。** 先頭数文字（key_prefix）も付けない。base64デコード・Crypto復号・unserializeした配列の丸ごと返却、すべて禁止。
  - 旧EasyWPSMTP `['smtp_settings']['password']` / v2 `['smtp']['pass']` / MailPoet `mta['password']`（平文）いずれも真偽のみ。
- `mailpoet_api_key` は `has_mailpoet_api_key`(bool) 化。**`mta`/settings 配列をレスポンスに丸ごと載せない。**
- host/port/encryption/auth/user(login)/from_* は平文可（user/from はメール＝PII。必要なら伏せる判断の余地あり）。

既存の作法: `class-stripe-base.php`（`is_set`:90 / `key_prefix`:73 / コメント「機微情報は絶対にそのまま返さない」:66-67、secret系は `*_set` のみで prefix 無し:127-143）、`class-inspector-plugins.php` `read_wpfs_licenses()`:335（返すのは `has_key`:368 のみ）。

## 5. WPSGへの配線（collector側・file:line）

`data.mail` を `/all` に載せるのに触るのは次の2ファイルだけ（WPSM受信は不要）。

### 5-1. require 追加 — `includes/class-site-inspector.php` `init()`

Stripe の require 群（L73〜80）の直後に、`mail/` 配下を **base→実装→dispatcher→entry の順**で5行 `require_once`。

### 5-2. `/all` 相乗り — `includes/class-inspect-rest-api.php` `route_all()`（L192〜224）

- L198 の後: `$mail_inspector = new WPSG_Inspector_Mail();`
- L205 の後: `$mail_result = self::run_inspector($mail_inspector);`
- warnings の `array_merge`（L207〜214）に `$mail_result['warnings']` を1要素追加
- 返却 `success(array(...))`（L216〜223）に `'mail' => $mail_result['data'],` を追加

**/all は既定(同梱)トークンでは 403**（`check_permission()` の `$sensitive=array('/membership','/all')` L154）なので、individual(explicit)トークン限定で安全。機微対応はこれで足りる。

### 5-3. 個別 `/mail` ルートは作らない（推奨）

作ると同梱トークン漏れのリスク（§7 HIGH-5）。どうしても要るなら `$authenticated` に `'mail'=>'route_mail'` ＋ `route_mail()`（`route_stripe` L270 を雛形）＋ **必ず `$sensitive` に `'/mail'` 追加**。

### 5-4. warnings の作法

各実装は Base の `add_warning($level,$code,$message,$details)` で push → `get_warnings()` → `run_inspector()` が取り出し → `route_all()` が array_merge → `success()` が `warnings` に格納。dispatcher を使う場合は Stripe/membership 同様、dispatcher の warnings を entry inspector の `$this->warnings` に吸い上げる。

## 6. 新規ファイル構成（`includes/inspectors/mail/`・Stripeと対称の4層）

Stripe（`class-stripe-base.php` 抽象 ＋ 実装N ＋ `class-stripe-dispatcher.php` ＋ トップ `class-inspector-stripe.php`）を写す。

1. `class-mail-base.php` … 抽象。`is_active()`/`get_implementation_id()`/`get_data()` を定義。ヘルパ `is_set`/`is_plugin_active`/`get_plugin_version`/`find_value`（Stripe基底から移植）に加え、**`wpdb()`/`table_exists()`（generic base 由来）も持たせる**（MailPoet のテーブル存在確認用）。`build_response()` に §1 のデフォルト構造。
2. `class-mail-easy-wp-smtp.php` … `const PLUGIN='easy-wp-smtp/easy-wp-smtp.php'`。§3-3 で v2/旧系を分岐、§3-1/3-2 のフィールド、`variant` を返す。
3. `class-mail-mailpoet.php` … `const PLUGIN='mailpoet/mailpoet.php'`。§3-4 の `mta`（SMTP要約）＋ §3-5 の `default_wp_mailer`。テーブル存在確認必須。
4. `class-mail-dispatcher.php` … Stripe dispatcher を写す。`apply_filters('wpsg_inspect_mail_inspectors', ...)` で将来拡張可。任意で §7 MED-3 の `mail_consistency` 警告。
5. `class-inspector-mail.php` … entry。`inspect()` が dispatcher を回し warnings 吸い上げ、非検出時 notice ＋ `baseline_mailer:'wp_default'`。

## 7. 落とし穴（実装前に必読）

- **HIGH-1 Easy WP SMTP 2系統**: v2 を主に。本体パス同一→版/名前空間クラスで系統確定してから対応 option を読む。フィールド名が系統で違う（旧 `type_encryption`/`autentication`'yes'/`username` vs v2 `encryption`/`auth`bool/`user`）。
- **HIGH-2 MailPoet 機微漏れ**: `mta['password']` は平文、`mailpoet_api_key` も同配列。**配列を丸ごと返さない。** 真偽のみ。
- **HIGH-3 第3問の格納（唯一の実機未確認点）**: `send_transactional_emails` 行の**実在・値型('1'かint 1か)・ネスト有無**は実機DBで確定してから実装（下記 §10）。ロジック（`empty()`）はソース確認済みで妥当だが、読む行を間違えると第3問が嘘になる。**生値を WPSM に解釈させず `default_wp_mailer` の明示 bool で返す。**
- **HIGH-4 /all 全体500の事故**: `new WPSG_Inspector_Mail()` は `route_all()` で try/catch 外、`run_inspector()` の `catch(Exception)` は `\Error`（Class not found）を捕まえない。require漏れ・クラス名typoで **/all 全体が500→68サイトに波及**。新規全ファイルを `php -l`＋実WPで `/all` を叩いてクラスロード確認してからリリース。
- **HIGH-5 個別 /mail の同梱トークン漏れ**: §5-3。作らないのが最安全。
- **MED-1 テーブル存在チェック**: `table_exists()`＋`maybe_unserialize()`。負荷は name検索2件で軽微、キャッシュ不要。
- **MED-2 非検出≠SMTP未設定**: 対象は Easy WP SMTP / MailPoet の2種のみ。他SMTP系（WP Mail SMTP・FluentSMTP・Post SMTP 等）が送信を担っていても「検出なし」になる。出力は「この2種の有無」であり送信方式の全容ではないと構造で示す（`baseline_mailer`）。
- **MED-3 競合検出（付加価値）**: MailPoet `default_wp_mailer=true` ＋ Easy WP SMTP 同時有効 = wp_mail 主導権衝突。Stripe の `check_mode_consistency()`（dispatcher L83）相当で warning を出すと有用。
- **命名衝突**: `mail` トップキー・`route_mail`・`WPSG_Inspector_Mail`・impl_id `easy-wp-smtp`/`mailpoet` は既存と衝突なし（確認済み）。
- **点検タイミング**: WPSG に点検cronは無い。完全 pull（WPSMがGETした時だけ実行）。**WPSG側でcron追加は不要。**

## 8. リリース手順（署名必須・約68本番サイトへ自動配布）

- **版は `wp-security-guard.php` の `Version:` ヘッダを上げる**（`WPSG_VERSION` 定数は存在しない）。`tools/release.sh` が `^Version: <版>$` を厳密一致grep。
- WPSMが機能検出に `inspector_version`（/health）を使うなら `includes/class-site-inspector.php` の `WPSG_INSPECTOR_VERSION`（現1.5.0）も上げる。
- 手順: CHANGELOG に節追加 → commit → `git tag v<版>` → `git push origin v<版>` → `tools/release.sh <版>`（yesで配布開始・半日で行き渡る）。
- **GitHub の画面からリリースしない。** 署名(.sig)無し・配布zip無しは各サイトが更新として扱わない／署名検証で拒否する（`class-update-verify.php`）。署名は WPSM 秘密鍵でしか作れず release.sh 専用。
- **未検証リリースは全本番サイトに波及する。** リリース前に実WordPressで正常系（EasyWPSMTP v2・MailPoet・両非有効）を必ず通す。

## 9. 実装チェックリスト（WPSG側のみ・順序付き）

1. **実格納の確定（最優先）**: 実機DBで MailPoet `{prefix}mailpoet_settings` の **`send_transactional_emails` 行の実在・値型・ネスト有無**（§10）、`mta` 構造、`easy_wp_smtp` 構造を確認。
2. `includes/inspectors/mail/` に5ファイル作成（§6・Stripe対称）。
3. マスク徹底（§4）: password は `*_set` のみ、配列・APIキーを載せない、復号/デコード禁止。
4. 配線 require（§5-1）。
5. 配線 `/all` 相乗り4行（§5-2）。個別 `/mail` は作らない（§5-3）。
6. 検証: 全新規ファイル `php -l` → ローカル実WPで v2/旧/MailPoet/両非有効 各ケースの `GET /inspect/all` を叩き `data.mail`・`password_set`・`default_wp_mailer`・warnings・HTTP200・JSON妥当を確認。**レスポンス全文を grep し平文パスワード/APIキー/mta配列が出ていないこと**を確認。
7. 版上げ＋CHANGELOG（§8）。
8. `tools/release.sh` でリリース（§8。GitHub画面からは作らない）。

## 10. 実装前に実機DBで確定する点（§7 HIGH-3）

対象サロン1サイトの DB で（読み取りのみ）:

```sql
-- 行が実在するか・値型
SELECT name, value FROM {prefix}mailpoet_settings
  WHERE name IN ('send_transactional_emails','mta_group');
-- mta は password を含むので値を直接見ず method だけ確認する等、機微に注意
```

確定したら `default_wp_mailer = empty($raw)` のまま実装してよい（未設定/''/'0'→デフォルトWP、'1'→MailPoet肩代わり）。

> 補足: 本仕様作成時に実機（devsalon/yoyincare）での確定を試みたが、ライブ本番サーバーへのSSHがアクセス制御で遮断されたため未実施。ロジックは MailPoet 一次ソース（`WordpressMailerReplacer`）で裏取り済み。実在確認だけが残課題。

## 付録: 一次ソース（Web裏取り）

- 旧EasyWPSMTP: `plugins.svn.wordpress.org/easy-wp-smtp/tags/1.5.1/easy-wp-smtp.php`
- v2 EasyWPSMTP Options(`easy_wp_smtp`/smtp群/Crypto/定数): `plugins.svn.wordpress.org/easy-wp-smtp/trunk/src/Options.php`
- MailPoet 値のserialize: `github.com/mailpoet/mailpoet` `lib/Entities/SettingEntity.php`・`lib/Settings/SettingsRepository.php`
- MailPoet `mta` 定数・SMTP分岐: `lib/Mailer/Mailer.php`・`lib/Mailer/MailerFactory.php`・`lib/Mailer/Methods/SMTP.php`
- MailPoet `send_transactional_emails` ロジック: `lib/Mailer/WordPress/WordpressMailerReplacer.php`
- MailPoet 高度な設定UI: `kb.mailpoet.com/article/292-choose-how-to-send-your-wordpress-websites-emails`
