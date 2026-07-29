# Phase 4 動作確認手順

このドキュメントは、Stripe決済設定 inspector (Phase 4) を1サイトに展開して動作確認するための手順をまとめたものです。

## 前提

- Phase 1〜3 (v2.3.0) の動作確認が完了している
- WordPress 管理者権限がある
- `curl` または WP-CLI が使える

## Phase 4 で追加されるファイル

```
includes/
├── inspectors/
│   ├── class-inspector-stripe.php              ← 新規 (top-level)
│   └── stripe/                                  ← 新規ディレクトリ
│       ├── class-stripe-base.php               ← 抽象基底クラス
│       ├── class-stripe-wc-gateway.php         ← WC Stripe Gateway
│       ├── class-stripe-wc-vendors-connect.php ← WC Vendors Stripe Connect
│       ├── class-stripe-wp-full-stripe.php     ← Themeisle版WP Full Stripe
│       ├── class-stripe-paypal-plugins.php     ← PayPal両対応
│       ├── class-stripe-bankpay.php            ← BankPay (Stripe非利用)
│       └── class-stripe-dispatcher.php
```

更新ファイル: `wp-security-guard.php` (Version: 2.4.0), `class-site-inspector.php`, `class-inspect-rest-api.php`, `inspector-settings.php`

---

## 手順1: REST API 動作確認

### 1-1. /inspect/stripe エンドポイント

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/stripe | jq .
```

期待される応答（マッチングサイト・WP Full Stripe + BankPay 同居の例）:

```json
{
  "success": true,
  "data": {
    "implementations": ["wp-full-stripe", "bankpay"],
    "details": {
      "wp-full-stripe": {
        "implementation": "wp-full-stripe",
        "version": "8.3.4",
        "mode": "live",
        "publishable_key_set": true,
        "publishable_key_prefix": "pk_live_",
        "secret_key_set": true,
        "test_publishable_key_set": true,
        "test_publishable_key_prefix": "pk_test_",
        "test_secret_key_set": true,
        "webhook_configured": true,
        "currency": "JPY",
        "settings_url": "...",
        "custom": {
          "option_name_used": "wpfs-options",
          "addon_members_active": true,
          "detected_keys": ["live_publishable_key", "live_secret_key", "..."]
        }
      },
      "bankpay": {
        "implementation": "bankpay",
        "version": "2.0.2",
        "mode": "configured",
        "publishable_key_set": false,
        "currency": "JPY",
        "custom": {
          "payment_method": "bank_transfer",
          "all_account_info_set": true
        }
      }
    },
    "mode_consistency": {
      "consistent": true,
      "modes": {"wp-full-stripe": "live"},
      "warning": null
    },
    "summary": {
      "implementations_count": 2,
      "live_implementations": 1,
      "test_implementations": 0,
      "configured_only": 1,
      "missing_keys_count": 0,
      "webhooks_configured": 1
    }
  },
  "warnings": []
}
```

### 1-2. 機微情報マスクの確認 (重要)

以下のフィールドが応答に含まれないことを確認:

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/stripe | \
  grep -i -E "(sk_live|sk_test|secret_key.*:.*sk|account_number.*:.*[0-9]|webhook_secret.*:.*whsec)"
```

期待される結果: **何も出力されない**

応答に含まれるのは:
- `publishable_key_prefix`: 先頭8文字のみ (例: `pk_live_`)
- `secret_key_set`: bool のみ
- `webhook_configured`: bool のみ
- `connect_application_id_prefix`: 先頭8文字のみ
- `account_number_set`: bool のみ (BankPay)

---

## 手順2: 各実装パターンでの動作確認

### 2-1. WC Stripe Gateway

WooCommerce + 公式 Stripe Gateway が有効なサイト:

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/stripe | \
  jq '.data.details["wc-stripe-gateway"]'
```

確認項目:
- `version` がプラグインのバージョンと一致
- `enabled` が WC設定 → 決済 → Stripe で有効になっているか
- `mode` が `testmode` 設定と整合
- 設定モードに応じた鍵セットの整合性
- `webhook_configured` で現在モードのWebhook設定有無

### 2-2. WP Full Stripe (Themeisle版)

> **重要**: WP Full Stripe (Themeisle版) のStripe鍵保存先オプション名は実機未確認です。  
> Phase 4では8候補のオプション名を順に試行し、見つかったものを `custom.option_name_used` で返します。

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/stripe | \
  jq '.data.details["wp-full-stripe"].custom'
```

確認すべき項目:
- `option_name_used`: どのオプション名で見つかったか (8候補の中から1つ)
- `detected_keys`: そのオプション内のキー一覧 (デバッグ用)

設定が見つからなかった場合:
```json
{
  "warnings": [{
    "level": "warning",
    "code": "wp_full_stripe_options_not_found",
    "message": "WP Full Stripeの設定オプションが見つかりませんでした..."
  }]
}
```

WP-CLIで実機確認:
```bash
wp option list --search="*Stripe*" --format=table | head -20
wp option list --search="*fullstripe*" --format=table | head -20
wp option list --search="*wpfs*" --format=table | head -20
```

実機調査結果が判明したら、`OPTION_CANDIDATES` 定数を確定値のみに整理する。

### 2-3. WC Vendors Stripe Connect

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/stripe | \
  jq '.data.details["wc-vendors-stripe-connect"]'
```

確認項目:
- `connect_application_id_set` (Connect Application ID は機微情報なので先頭8文字のみ返す)
- `commission_rate` (ベンダー手数料率)

### 2-4. PayPal 両プラグイン対応

公式 woocommerce-paypal-payments と Payment Plugins for PayPal の両方に対応:

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/stripe | \
  jq '.data.details["paypal-plugins"]'
```

`custom.plugin_used` でどちらが使われているかを判別:
- `"woocommerce-paypal-payments"` → 公式
- `"pymntpls-paypal-woocommerce"` → Payment Plugins製

### 2-5. BankPay (Stripe非利用)

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/stripe | \
  jq '.data.details["bankpay"]'
```

確認項目:
- `mode` が `configured` か `unconfigured`
- `custom.account_name_set`, `account_number_set`, `bank_name_set`, `branch_name_set` の各 bool
- 振込先口座の中身が**含まれていない**こと (重要)

部分的にしか設定されていない場合、`bankpay_account_partial` warning が出ること。

---

## 手順3: モード一貫性チェック

複数の決済実装が同居している場合、本番モード/テストモードが一致していることを確認:

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/stripe | \
  jq '.data.mode_consistency'
```

期待される応答 (一致時):
```json
{
  "consistent": true,
  "modes": {"wc-stripe-gateway": "live", "wp-full-stripe": "live"},
  "warning": null
}
```

不一致時 (例: WC Stripe が Live、WP Full Stripe が Test):
```json
{
  "consistent": false,
  "modes": {"wc-stripe-gateway": "live", "wp-full-stripe": "test"},
  "warning": "複数の決済実装でモードが不一致"
}
```

加えて `warnings` に以下が含まれる:
```json
{
  "level": "warning",
  "code": "stripe_mode_inconsistent",
  "message": "複数の決済実装でモード(本番/テスト)が一致していません",
  "details": {"modes": {...}}
}
```

---

## 手順4: 鍵不足検出

設定されているモードに対して鍵が不足している場合の警告:

| 実装 | モード | 期待される鍵 | 不足時の警告コード |
|---|---|---|---|
| WC Stripe Gateway | live | publishable_key, secret_key | `wc_stripe_live_keys_missing` |
| WC Stripe Gateway | test | test_publishable_key, test_secret_key | `wc_stripe_test_keys_missing` |
| WP Full Stripe | live | live_publishable_key, live_secret_key | `wp_full_stripe_live_keys_missing` (notice) |
| WP Full Stripe | test | test_publishable_key, test_secret_key | `wp_full_stripe_test_keys_missing` |

---

## 手順5: /inspect/all で一括確認

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/all | jq '.data | keys'
```

期待される応答:
```json
[
  "core",
  "features",
  "membership",
  "plugins",
  "stripe",
  "theme"
]
```

`stripe` キーが追加されていることを確認。

---

## チェックリスト

### 基本動作
- [ ] バージョンが 2.4.0 になっている
- [ ] Phase 1〜3 の機能が引き続き動作する
- [ ] `/inspect/stripe` エンドポイントが応答する
- [ ] `/inspect/all` のレスポンスに `data.stripe` が含まれる
- [ ] 管理画面のエンドポイント一覧に `/stripe` が追加されている

### 機微情報マスク (重要)
- [ ] `secret_key`, `sk_live_*`, `sk_test_*` が応答に**一切含まれない**
- [ ] `webhook_secret` が応答に**一切含まれない** (boolのみ)
- [ ] BankPay の `account_number` などの中身が応答に**一切含まれない**
- [ ] `publishable_key_prefix` は先頭8文字のみ

### WC Stripe Gateway
- [ ] testmode 切替で `mode` が変わる
- [ ] 鍵不足時に `wc_stripe_*_keys_missing` warning が出る

### WP Full Stripe
- [ ] `option_name_used` で見つかったオプション名が報告される
- [ ] `detected_keys` でオプション内のキー一覧が見える
- [ ] オプションが見つからない場合 `wp_full_stripe_options_not_found` warning

### モード一貫性
- [ ] 複数実装で同一モード時は `consistent: true`
- [ ] 不一致時は `consistent: false` + `stripe_mode_inconsistent` warning

### 例外耐性
- [ ] 1つの inspector が壊れても他は動作継続
- [ ] 壊れた inspector の警告は `stripe_inspector_exception` で記録

---

## トラブルシューティング

| 症状 | 対処 |
|---|---|
| WP Full Stripe の `option_name_used` が null | 8候補すべてに合致しなかった。WP-CLI で `wp option list` を実行し、`OPTION_CANDIDATES` 定数を更新。応答の `tried_options` フィールドで何を試したか確認可 |
| WC Stripe で `mode` が "unknown" | `woocommerce_stripe_settings` オプションが空。プラグインを一度設定し直すか、手動でDB確認 |
| BankPay の `mode` が "unconfigured" だが実際は使えている | `bkp_op_account_*` の4つすべて設定されていることが必要。一部だけ設定の場合は `bankpay_account_partial` warning が出る |

---

## 次のステップ

Phase 4 が問題なく動作したら、**Phase 5 (Python チェックランナー & Streamlit ダッシュボード)** に進みます。

WP-CLIでの調査結果をいただければ、`WPSG_Stripe_WP_Full_Stripe::OPTION_CANDIDATES` を確定値1つに絞り込み、コードを簡潔化できます (現在は8候補を順次試行)。
