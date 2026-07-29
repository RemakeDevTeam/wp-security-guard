# Phase 1 動作確認手順

このドキュメントは、点検モジュール (Phase 1) を1サイトに展開して動作確認するための手順をまとめたものです。

## 前提

- 検証対象サイトに `WP Security Guard v2.0.0` が既に導入・有効化されている
- WordPress 管理者権限がある
- WP-CLI もしくは curl が使える環境がある

---

## 手順1: ファイルの配置

`wp-security-guard` プラグインフォルダを v2.1.0 に置き換える、または以下のファイルだけを追加する。

### 追加されるファイル(全10ファイル)

```
wp-security-guard/
├── wp-security-guard.php           ← 変更 (Version: 2.1.0、点検モジュール読込追加)
├── CHANGELOG.md                    ← 変更
├── README.md                       ← 変更
└── includes/                       ← 新規ディレクトリ
    ├── class-site-inspector.php    ← 新規
    ├── class-inspect-token.php     ← 新規
    ├── class-inspect-rest-api.php  ← 新規
    ├── inspectors/
    │   ├── class-inspector-base.php    ← 新規
    │   ├── class-inspector-core.php    ← 新規
    │   ├── class-inspector-theme.php   ← 新規
    │   └── class-inspector-plugins.php ← 新規
    └── admin/
        ├── class-admin-page-extension.php ← 新規
        └── views/
            └── inspector-settings.php     ← 新規
```

### 配置方法の選択肢

**方法A: プラグインフォルダ全体を差し替え**
1. 既存の `/wp-content/plugins/wp-security-guard/` をバックアップ
2. v2.1.0 のフォルダで上書き

**方法B: 差分のみ追加**
1. `wp-security-guard.php` を新版に差し替え
2. `includes/` ディレクトリを丸ごと追加
3. `CHANGELOG.md` / `README.md` を更新

---

## 手順2: 動作確認 (管理画面)

### 2-1. プラグインバージョン確認

WordPress 管理画面 → プラグイン一覧 で `WP Security Guard` のバージョンが `2.1.0` に表示されること。

### 2-2. 既存機能が壊れていないことを確認

「セキュリティガード」設定画面を開き、既存の以下のセクションが従来通り表示されること:

1. XML-RPC遮断
2. ユーザー名列挙対策
3. WPバージョン情報隠蔽
4. アプリケーションパスワード無効化
5. CF7スパム対策

### 2-3. 新セクションの確認

「セキュリティガード」設定画面の最下部に、以下が追加されていること:

- 「6. サイト点検設定」見出し
- 「点検アクセストークン」状態表示
- 「トークンを生成」ボタン
- 「許可IP制限」入力欄
- エンドポイント一覧表

### 2-4. トークン生成

1. 「トークンを生成」ボタンをクリック
2. 確認ダイアログで OK
3. ページ上部に「⚠️ 新しいトークン (1度だけ表示されます)」が表示される
4. 表示されたトークンを **コピーして安全な場所に保管**

---

## 手順3: REST API 動作確認 (curl)

`<TOKEN>` には手順 2-4 で取得したトークンを、`<URL>` にはサイトのURL (末尾スラッシュなし) を入れてください。

### 3-1. ヘルスチェック (認証不要)

```bash
curl <URL>/wp-json/wpsg/v1/inspect/health | jq .
```

期待される応答:
```json
{
  "success": true,
  "checked_at": "2026-04-30T14:23:11+09:00",
  "data": {
    "status": "ok",
    "wp_version": "6.4.x",
    "inspector_version": "1.0.0",
    "token_configured": true
  },
  "warnings": []
}
```

### 3-2. 認証なしでアクセス試行 (401を期待)

```bash
curl -i <URL>/wp-json/wpsg/v1/inspect/core
```

期待される応答 (HTTP 401):
```json
{
  "code": "unauthorized",
  "message": "Authorization header required.",
  "data": {"status": 401}
}
```

### 3-3. 不正トークンでアクセス試行 (403を期待)

```bash
curl -i -H "Authorization: Bearer invalid_token_xxxxx" \
     <URL>/wp-json/wpsg/v1/inspect/core
```

期待される応答 (HTTP 403):
```json
{
  "code": "invalid_token",
  "message": "Access denied.",
  "data": {"status": 403}
}
```

### 3-4. 正しいトークンでコア情報取得

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/core | jq .
```

期待される応答 (主要フィールド):
- `success: true`
- `data.wp_version` ← WordPressバージョン
- `data.php_version` ← PHPバージョン
- `data.mysql_version` ← MySQLバージョン
- `data.table_prefix` ← `wp_` などのテーブルプレフィックス

### 3-5. テーマ情報取得

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/theme | jq .
```

期待される応答 (主要フィールド):
- `data.active.name` ← 有効テーマ名
- `data.active.version` ← バージョン
- `data.is_child_theme` ← 子テーマかどうか
- `data.parent` ← 親テーマ情報 (子テーマの場合)

### 3-6. プラグイン一覧取得 (旧版残存検出を含む)

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/plugins | jq .
```

期待される応答 (主要フィールド):
- `data.active[]` ← 有効プラグイン一覧
- `data.inactive[]` ← 無効プラグイン一覧
- `data.legacy_residual_count` ← 旧版残存数 (Mammothology製の wp-full-pay-* がインストールされていれば1以上)
- `warnings[]` ← `legacy_plugin_residual` 警告 (旧版が残存している場合のみ)

**重要**: 検証対象が WP Full Pay 系のサイトの場合、Mammothology 旧版が残存しているとここで検出されます。

### 3-7. 全項目一括取得

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/all | jq .
```

期待される応答:
- `data.core` ← コア情報
- `data.theme` ← テーマ情報
- `data.plugins` ← プラグイン情報
- `warnings[]` ← 各 inspector の警告を統合

---

## 手順4: レート制限の動作確認 (任意)

1分間に60回を超えるリクエストを送ると 429 になります。

```bash
for i in {1..70}; do
  curl -s -o /dev/null -w "%{http_code} " \
    -H "Authorization: Bearer <TOKEN>" \
    <URL>/wp-json/wpsg/v1/inspect/health
done
echo
```

最初の60回は `200`、それ以降は `200`(ヘルスチェックは認証不要) ですが、認証付きエンドポイントでは61回目以降 `429` が返ります:

```bash
for i in {1..70}; do
  curl -s -o /dev/null -w "%{http_code} " \
    -H "Authorization: Bearer <TOKEN>" \
    <URL>/wp-json/wpsg/v1/inspect/core
done
echo
# 結果例: 200 200 200 ... 200 429 429 429 (1分間に60回超えた以降429)
```

---

## 手順5: トークン再生成・削除の確認

### 5-1. 再生成

1. 管理画面でトークンを再生成
2. 新しいトークンが表示される
3. **古いトークン**で curl すると `403 invalid_token` になることを確認
4. **新しいトークン**で curl すると正常に動作することを確認

### 5-2. 削除

1. 管理画面で「トークンを削除」ボタンをクリック
2. ヘルスチェックの応答で `token_configured: false` になることを確認
3. 認証付きエンドポイントは `401 unauthorized` になることを確認

---

## トラブルシューティング

| 症状 | 原因と対処 |
|---|---|
| `404 rest_no_route` | プラグイン未有効化、または更新前のキャッシュ。プラグイン一覧で v2.1.0 を確認、パーマリンク設定を一度開いて保存 |
| `401 unauthorized` でも認証ヘッダーを付けている | 一部サーバーは `Authorization` ヘッダーを上書き。`-H "Authorization: Bearer xxx"` ではなく `--user :xxx` を試す、または `.htaccess` に `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` を追加 |
| 「セキュリティガード」画面に「6. サイト点検設定」が表示されない | `WPSG_Admin_Page_Extension::init()` のフック名が一致していない可能性。管理画面のページスラッグが `toplevel_page_wp-security-guard` であることを確認 |
| 既存のセキュリティ機能が表示されない | `wp-security-guard.php` の差し替えで既存コードが消えている可能性。バックアップから復元 |

---

## チェックリスト

- [ ] プラグインバージョンが 2.1.0 になっている
- [ ] 既存のセキュリティ機能 5 種類が従来通り表示されている
- [ ] 「6. サイト点検設定」セクションが表示されている
- [ ] トークンが生成できる (1度だけ表示される)
- [ ] `health` エンドポイントが認証なしで応答する
- [ ] 認証なしで `core` にアクセスすると 401
- [ ] 不正トークンで `core` にアクセスすると 403
- [ ] 正しいトークンで `core` / `theme` / `plugins` / `all` が取得できる
- [ ] 旧版プラグインが残存している場合、`plugins` 応答で検出される
- [ ] レート制限が動作する (1分60回超で429)
- [ ] トークン再生成で旧トークンが無効になる
- [ ] トークン削除で全認証付きエンドポイントが利用不可になる

---

## 次のステップ

Phase 1 が問題なく動作したら、以下の順で進めます。

1. **Phase 2**: 機能フラグ管理 (自動検出+手動上書き)
2. **Phase 3**: 会員管理プラグイン別 inspector (UM / WP Full Stripe / WC / WC Vendors / WP Crowdfunding / BankPay)
3. **Phase 4**: Stripe inspector
4. **Phase 5**: Python チェックランナー & Streamlit ダッシュボード
5. **Phase 6**: Playwright E2E

Phase 4 着手前に、WP Full Stripe (Themeisle版) の Stripe 鍵オプション名を WP-CLI で調査いただくと完全な実装が可能です:

```bash
wp option list --search="*Stripe*" --format=table | head -20
wp option list --search="*fullstripe*" --format=table | head -20
wp option list --search="*wpfs*" --format=table | head -20
```
