# Phase 2 動作確認手順

このドキュメントは、機能フラグ管理モジュール (Phase 2) を1サイトに展開して動作確認するための手順をまとめたものです。

## 前提

- Phase 1 (v2.1.0) の動作確認が完了している
- WordPress 管理者権限がある
- WP-CLI もしくは curl が使える環境がある

---

## 手順1: ファイルの配置

Phase 1 と同じ手順で `wp-security-guard` プラグインを v2.2.0 に置き換える。

### Phase 2 で追加されるファイル

```
includes/
├── class-feature-registry.php             ← 新規
├── class-feature-detector.php             ← 新規
├── class-feature-storage.php              ← 新規
├── inspectors/
│   └── class-inspector-features.php       ← 新規
└── admin/
    └── views/
        └── features-editor.php            ← 新規

更新ファイル:
- wp-security-guard.php             (Version: 2.2.0)
- includes/class-site-inspector.php (Phase 2 コンポーネント読込追加)
- includes/class-inspect-rest-api.php (/inspect/features 追加、/all 拡張)
- includes/admin/class-admin-page-extension.php (機能フラグセクション追加)
- includes/admin/views/inspector-settings.php (エンドポイント一覧更新)
```

---

## 手順2: 動作確認 (管理画面)

### 2-1. プラグインバージョン確認

WordPress 管理画面 → プラグイン一覧 で `WP Security Guard` のバージョンが `2.2.0` に表示されること。

### 2-2. 新セクションの確認

「セキュリティガード」設定画面の最下部に、以下が表示されること:

- 「6. サイト点検設定」(Phase 1 で追加済み)
- **「7. サイト機能フラグ管理」 (Phase 2 で新規追加)**
  - 基本情報: システム種別の選択、サイトラベル入力
  - 機能フラグ一覧 (28機能、7カテゴリ別)
  - 「機能フラグを保存」ボタン
  - 「自動検出してプリセット」ボタン

### 2-3. 初回設定 (自動検出 → 確認 → 保存)

1. 「7. サイト機能フラグ管理」セクションを開く
2. **「機能フラグが未設定です」**の警告が表示されることを確認
3. システム種別ドロップダウンから対象サイトの種別を選ぶ (例: マッチングシステム)
4. サイトラベルに識別子を入力 (任意。例: `matching-001`)
5. **「自動検出してプリセット」**ボタンをクリック
6. 確認ダイアログでOK
7. リロード後、画面に以下が反映されていること:
   - 検出結果列に「✓ あり / なし」が表示されている
   - 信頼度列に「●検出 / ●推定 / ●弱 / ●不可」のいずれかが表示されている
   - ON/OFFチェックボックスが、検出結果に基づいて自動でON/OFFされている
8. 検出結果を見ながら、**手動で**正しい状態に修正する
   - 例: 検出されなかった独自実装の機能をONに
   - 例: テスト用に有効化されているがサイトでは使わない機能をOFFに
9. 備考欄に必要に応じてメモを書く
10. **「機能フラグを保存」**ボタンをクリック
11. 「機能フラグを保存しました」の通知が表示されること

### 2-4. 設定後の状態確認

1. 設定画面を再読み込みすると、保存値がチェックボックスに反映されていること
2. 「最終自動検出」「最終更新」のタイムスタンプが表示されていること
3. 保存値と検出値が異なる行は **黄色背景** でハイライトされ、`⚠ 保存値と検出値が乖離` と表示されること

---

## 手順3: REST API 動作確認 (curl)

### 3-1. /inspect/features エンドポイント

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/features | jq .
```

### 期待される応答 (設定済みの場合)

```json
{
  "success": true,
  "checked_at": "2026-04-30T15:00:00+09:00",
  "data": {
    "configured": true,
    "system_type": "matching",
    "system_type_label": "マッチングシステム",
    "site_label": "matching-001",
    "notes": "...",
    "features": {
      "membership": {
        "label": "会員機能",
        "category": "general",
        "saved": true,
        "detected": true,
        "signals": ["ultimate-member", "wp-full-stripe"],
        "confidence": "high",
        "in_sync": true
      },
      "matching": {
        "label": "マッチング",
        "category": "matching",
        "saved": true,
        "detected": false,
        "signals": [],
        "confidence": "none",
        "in_sync": null
      }
    },
    "discrepancies": [],
    "summary": {
      "total_features": 28,
      "saved_enabled": 10,
      "detected_enabled": 9,
      "high_confidence_count": 12,
      "no_signal_count": 6
    },
    "last_updated_at": "2026-04-30T15:00:00+09:00",
    "last_synced_at": "2026-04-30T14:55:00+09:00",
    "available_system_types": { ... },
    "available_categories": { ... }
  },
  "warnings": []
}
```

### 重要なフィールドの解釈

| フィールド | 意味 |
|---|---|
| `configured` | trueなら手動設定済み、falseなら未設定 |
| `features.<id>.saved` | 管理画面で保存された確定値 |
| `features.<id>.detected` | 自動検出での判定 |
| `features.<id>.signals` | 検出に使ったプラグイン名等のシグナル |
| `features.<id>.confidence` | high/medium/low/none |
| `features.<id>.in_sync` | saved == detected か。confidence=noneのときはnull |
| `discrepancies[]` | 保存値と検出値が乖離している機能のリスト |

### 3-2. 未設定状態でのアクセス

サイトを新規セットアップ直後など、機能フラグが未設定の場合:

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/features | jq .
```

応答:
```json
{
  "success": true,
  "data": {
    "configured": false,
    "system_type": null,
    "site_label": null,
    "features": {
      "membership": {
        "saved": null,
        "detected": true,
        "signals": ["ultimate-member"],
        "confidence": "high",
        "in_sync": null
      }
    },
    ...
  },
  "warnings": [
    {
      "level": "warning",
      "code": "features_not_configured",
      "message": "機能フラグが未設定です。管理画面で初期設定を行ってください"
    }
  ]
}
```

### 3-3. /inspect/all で機能フラグも一括取得

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/all | jq '.data.features.summary'
```

`/inspect/all` のレスポンスに `data.features` が含まれることを確認する。

---

## 手順4: 乖離検知の動作確認

### 4-1. 意図的に乖離を作る

1. 管理画面で `forum` を **OFF** に設定して保存
2. (実機環境で `bbpress` プラグインを有効化)
3. 設定画面を再読み込み

期待される表示:
- `forum` の行が **黄色背景** でハイライト
- 「⚠ 保存値と検出値が乖離」と表示
- 検出結果: 「✓ あり」、信頼度: 「●検出」

### 4-2. /inspect/features で乖離が報告される

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/features | jq '.data.discrepancies'
```

期待される応答:
```json
[
  {
    "feature": "forum",
    "label": "フォーラム",
    "saved": false,
    "detected": true,
    "confidence": "high",
    "signals": ["bbpress"],
    "message": "フォーラム: 保存値はOFFだが、検出されました(シグナル: bbpress)"
  }
]
```

`warnings` にも以下が含まれること:
```json
{
  "level": "warning",
  "code": "feature_discrepancy",
  "message": "1件の機能フラグで保存値と検出値が乖離しています",
  "details": { "features": ["forum"] }
}
```

---

## 手順5: 信頼度別の動作確認

### 5-1. 信頼度 `high` (強いシグナル)

- WooCommerce が有効 → `shop`, `cart` が high
- WP Full Stripe が有効でテーブルにデータ → `subscription`, `stripe` が high
- BankPay が有効 → `bank_transfer`, `subscription` (月額/年額あり) が high

### 5-2. 信頼度 `medium` (推測可能)

- BuddyPress通知モジュール → `notification` が medium
- UM Member Directory プラグインのみ → `profile_search` が medium

### 5-3. 信頼度 `none` (検出不可)

以下の機能は検出ロジックを持たないため、必ず none となる:
- `matching` (独自実装が多い)
- `favorites` (独自実装が多い)
- `live_streaming`
- `live_commerce`

これらは管理画面で**手動で設定**する必要がある。  
none の機能は乖離扱いされない (`in_sync: null`、`discrepancies` に含まれない)。

---

## 手順6: 機能フラグ設定の永続性確認

1. プラグインを一度無効化 → 有効化
2. 設定画面を開く
3. 保存した設定値・サイトラベル・備考が**残っていること**

(プラグイン無効化時に設定値は削除されない仕様)

---

## チェックリスト

- [ ] バージョンが 2.2.0 になっている
- [ ] Phase 1 の機能 (トークン管理・REST API) が引き続き動作する
- [ ] 「7. サイト機能フラグ管理」セクションが表示される
- [ ] 「機能フラグが未設定です」の警告が初期表示される
- [ ] 28機能 × 7カテゴリのチェックボックスが表示される
- [ ] 「自動検出してプリセット」が動作する
- [ ] 保存後、`/inspect/features` で `configured: true` が返る
- [ ] `features.<id>.saved` と `features.<id>.detected` が両方含まれる
- [ ] 乖離がある機能は `discrepancies` に含まれる
- [ ] confidence=none の機能は `discrepancies` に含まれない
- [ ] 乖離がある場合、管理画面で行が黄色ハイライトされる
- [ ] `/inspect/all` のレスポンスに `data.features` が含まれる
- [ ] 不正な system_type を送っても保存されない
- [ ] サイトラベルがサニタイズされる (英数字+ハイフン+アンダースコアのみ)

---

## トラブルシューティング

| 症状 | 原因と対処 |
|---|---|
| 「7. サイト機能フラグ管理」が表示されない | プラグインが v2.2.0 になっているか確認。`includes/class-feature-registry.php` 等のファイルが存在するか確認 |
| 自動検出ボタンを押しても何も変わらない | 検出ロジックの上で何も検出できない場合に発生する可能性。各機能の検出シグナル列を確認、ほぼ全部 `none` の場合は対象プラグインが本当に無効化されている可能性 |
| 保存しても次回読み込み時に消える | `wp_options.wpsg_site_features` への書き込みが失敗している。デバッグログ確認、または `update_option` の戻り値を確認 |
| 乖離扱いになるはずの機能が `discrepancies` に出ない | confidence=none の機能は意図的に乖離扱いしない仕様。検出ロジックを追加するか、フィルターフックでサイト固有の検出を追加 |

---

## 80サイトへの初期登録運用

以下の流れで運用することを想定:

1. 各サイトに v2.2.0 を展開 (Phase 1 と同じ)
2. 管理画面で「自動検出してプリセット」を実行
3. 自動検出で漏れた機能 (confidence=none) を手動で正しく設定
4. システム種別とサイトラベルを設定して保存
5. `/inspect/features` の結果が正しく取れることを確認
6. 次のサイトへ

5分/サイト × 80サイト = 約7時間の作業量を想定。

---

## 次のステップ

Phase 2 が問題なく動作したら、以下の順で進めます。

- **Phase 3**: 会員管理プラグイン別 inspector
  - Ultimate Member / WP Full Stripe (Themeisle版) / WooCommerce / WC Vendors / WP Crowdfunding / BankPay
  - `/inspect/membership` エンドポイント
- **Phase 4**: Stripe inspector (`/inspect/stripe`)
- **Phase 5**: Python チェックランナー & Streamlit ダッシュボード
- **Phase 6**: Playwright E2E

Phase 4 着手前に WP Full Stripe の Stripe 鍵オプション名を WP-CLI で調査いただけると完全な実装が可能です:

```bash
wp option list --search="*Stripe*" --format=table | head -20
wp option list --search="*fullstripe*" --format=table | head -20
wp option list --search="*wpfs*" --format=table | head -20
```
