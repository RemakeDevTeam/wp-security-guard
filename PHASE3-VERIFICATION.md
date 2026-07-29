# Phase 3 動作確認手順

このドキュメントは、会員管理プラグイン別inspector (Phase 3) を1サイトに展開して動作確認するための手順をまとめたものです。

## 前提

- Phase 1 (v2.1.0) と Phase 2 (v2.2.0) の動作確認が完了している
- WordPress 管理者権限がある
- WP-CLI もしくは curl が使える環境がある

## Phase 3 で追加されるファイル

```
includes/
├── inspectors/
│   ├── class-inspector-membership.php          ← 新規
│   └── membership/                             ← 新規ディレクトリ
│       ├── class-membership-base.php           ← 新規 (抽象基底クラス)
│       ├── class-membership-ultimate-member.php ← 新規
│       ├── class-membership-wp-full-stripe.php ← 新規
│       ├── class-membership-woocommerce.php    ← 新規
│       ├── class-membership-wc-vendors.php     ← 新規
│       ├── class-membership-wp-crowdfunding.php ← 新規
│       ├── class-membership-bankpay.php        ← 新規
│       └── class-membership-dispatcher.php     ← 新規

更新ファイル:
- wp-security-guard.php                         (Version: 2.3.0)
- includes/class-site-inspector.php             (Phase 3 コンポーネント読込追加)
- includes/class-inspect-rest-api.php           (/inspect/membership 追加、/all 拡張)
- includes/admin/views/inspector-settings.php   (エンドポイント一覧に /membership)
```

---

## 手順1: REST API での動作確認

### 1-1. /inspect/membership エンドポイント

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/membership | jq .
```

### 期待される応答（マッチングサイトの例）

```json
{
  "success": true,
  "checked_at": "2026-04-30T15:00:00+09:00",
  "data": {
    "implementations": ["ultimate-member", "wp-full-stripe", "bankpay"],
    "products": [
      {
        "id": "ultimate-member:role:um_paid_member",
        "implementation": "ultimate-member",
        "product_type": "role",
        "name": "有料会員",
        "role_key": "um_paid_member",
        "um_managed": true,
        "standard_role": false,
        "user_count": 247,
        "amount": null,
        "currency": null
      },
      {
        "id": "subscription:2:price_1RFYaTLr...",
        "implementation": "wp-full-stripe",
        "form_id": 2,
        "form_slug": "gold",
        "form_name": "有料会員",
        "form_type": "subscription",
        "plan_name": "サービス利用料",
        "amount": 1200,
        "currency": "JPY",
        "interval": "year",
        "interval_count": 1,
        "stripe_price_id": "price_1RFYaTLr..."
      },
      {
        "id": "bankpay:monthly",
        "implementation": "bankpay",
        "product_type": "subscription",
        "name": "月会費プラン",
        "amount": 1980,
        "currency": "JPY",
        "interval": "month",
        "interval_count": 1,
        "entrance_fee": 5000,
        "tax_rate_percent": 10,
        "payment_method": "bank_transfer"
      }
    ],
    "metadata": {
      "ultimate-member": {
        "plugin_version": "2.8.7",
        "roles_count_total": 8,
        "roles_count_um": 3,
        "um_extensions": [...]
      },
      "wp-full-stripe": {
        "plugin_version": "8.3.4",
        "addon_active": true,
        "addon_version": "1.0.2",
        "legacy_plugins": [],
        "members_stats": {
          "total": 250,
          "live_count": 245,
          "test_count": 5,
          "by_status": [...]
        },
        "livemode_consistency": {"mixed": true, ...}
      },
      "bankpay": {
        "plugin_version": "2.0.2",
        "tax_rate_percent": 10,
        "paid_users_count": 247,
        "unpaid_users_count": 3,
        "has_bank_account_set": true
      }
    },
    "summary": {
      "total_products": 5,
      "implementations_count": 3,
      "has_subscriptions": true,
      "currencies": ["JPY"],
      "products_by_type": {
        "role": 1,
        "subscription": 4
      }
    }
  },
  "warnings": [
    {
      "level": "warning",
      "code": "wp_full_stripe_livemode_mixed",
      "message": "WP Full Stripeの会員データに本番モード/テストモードが混在しています"
    }
  ]
}
```

### 1-2. /inspect/all で全項目一括取得

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/all | jq '.data | keys'
```

期待される応答 (新たに `membership` キーが追加されている):
```json
[
  "core",
  "features",
  "membership",
  "plugins",
  "theme"
]
```

---

## 手順2: 各実装パターンでの動作確認

サイトの実装パターンによって、`implementations` 配列に含まれる値が異なります。

| 実装パターン | 期待される implementations |
|---|---|
| マッチング (UM + WP Full Stripe) | `["ultimate-member", "wp-full-stripe"]` |
| マッチング (UM + WP Full Stripe + BankPay) | `["ultimate-member", "wp-full-stripe", "bankpay"]` |
| サロン (UM + WP Full Stripe) | `["ultimate-member", "wp-full-stripe"]` |
| ライブコマース (WC のみ) | `["woocommerce"]` |
| ECモール (WC + WC Vendors) | `["woocommerce", "wc-vendors"]` |
| CRMサブスク (UM + WP Full Stripe) | `["ultimate-member", "wp-full-stripe"]` |
| CRMサブスク (UM + BankPay) | `["ultimate-member", "bankpay"]` |
| CF (WC + WP Crowdfunding) | `["woocommerce", "wp-crowdfunding"]` |

### 2-1. UM + WP Full Stripe + BankPay の同居サイト

特に重要な確認項目:

- `implementations` に3つ全て含まれる
- `products` に各実装の製品が混在して並ぶ
- `metadata` に各実装の情報が独立して入る
- BankPay の `paid_users_count` と UM の `user_count` (該当ロール) が概ね一致

### 2-2. WP Full Stripe (Themeisle版) の確認

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/membership | \
  jq '.data.metadata["wp-full-stripe"]'
```

確認項目:
- `plugin_version` が現行のバージョン
- `addon_active` が true (Members アドオンが入っているなら)
- `tables_present` が6種類のフォームテーブルを含む
- `members_stats.total` が会員数と一致
- `livemode_consistency.mixed` が想定通り (テストモードと本番モードが混在していない)

### 2-3. 旧版プラグイン残存の確認

WP Full Pay (旧版・Mammothology製) が残存しているサイトでは:

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/membership | \
  jq '.data.metadata["wp-full-stripe"].legacy_plugins'
```

期待される応答:
```json
[
  {
    "file": "wp-full-pay-fm-premium/wp-full-pay-fm-premium.php",
    "active": false,
    "version": "7.1.7",
    "warning": "旧版(Mammothology製)が残存しています。削除を推奨"
  }
]
```

`warnings` にも以下が出ること:
```json
{
  "level": "warning",
  "code": "wp_full_stripe_legacy_residual",
  "message": "1件のWP Full Pay旧版プラグインが残存しています"
}
```

### 2-4. BankPay の確認

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/membership | \
  jq '.data | { products: [.products[] | select(.implementation == "bankpay")], metadata: .metadata.bankpay }'
```

確認項目:
- 月額のみのサイトなら `products` に1件 (interval: month)
- 月額+年額のサイトなら `products` に2件
- `metadata.bankpay.has_bank_account_set` で口座設定有無のみ判別 (中身は機微情報のため返らない)
- `metadata.bankpay.paid_users_count` が現実の会員数とほぼ一致

---

## 手順3: 機微情報マスクの確認

以下の項目は **絶対に応答に含まれない** ことを確認する:

```bash
curl -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/membership | \
  grep -i -E "(account_name|account_number|secret|password)"
```

期待される応答: **何も出力されない**

(出力された場合は機微情報漏洩。すぐに開発側に連絡が必要)

---

## 手順4: パフォーマンス確認

会員数・商品数が多いサイトでは応答時間に注意。

```bash
time curl -s -o /dev/null \
     -H "Authorization: Bearer <TOKEN>" \
     <URL>/wp-json/wpsg/v1/inspect/membership
```

期待される応答時間:
- 通常サイト: 1秒以内
- 大規模ECサイト (商品500件以上): 2〜3秒程度
- 巨大サイト (5000件以上): 5秒程度（WC商品取得が500件で打ち切られる旨の warning が出る）

---

## 手順5: エラーハンドリング確認

### 5-1. プラグインが破損している場合

例えば `WC Vendors` のテーブルが何らかの理由で削除されている場合:
- `is_active()` は plugin_active で true になる
- `get_metadata().commission_due_count` は null (テーブル不在のため)
- 例外発生時には `warnings` に `membership_inspector_exception` が記録される
- 他のinspectorは正常に動作し続ける (1つの inspector が壊れても全体は止まらない)

### 5-2. WP Full Stripe の decoratedPlans JSON 破損時

何らかの理由でJSONが壊れている場合:
```json
{
  "warnings": [
    {
      "level": "warning",
      "code": "wp_full_stripe_decorated_plans_parse_error",
      "message": "プラン情報のJSON解析に失敗 (form_id=2, slug=gold)",
      "details": {"json_last_error": "Syntax error"}
    }
  ]
}
```

該当フォームの製品はスキップされ、他のフォームは正常に取得される。

---

## チェックリスト

### 基本動作
- [ ] バージョンが 2.3.0 になっている
- [ ] Phase 1 / Phase 2 の機能が引き続き動作する
- [ ] `/inspect/membership` エンドポイントが応答する
- [ ] `/inspect/all` のレスポンスに `data.membership` が含まれる
- [ ] 管理画面の「6. サイト点検設定」のエンドポイント一覧に `/membership` が追加されている

### Ultimate Member
- [ ] サイトが UM を使っているなら `implementations` に `ultimate-member` が含まれる
- [ ] WP標準ロールと UM管理ロールが正しく区別される (`um_managed` フラグ)
- [ ] 各ロールの `user_count` が正しい数値

### WP Full Stripe (Themeisle版)
- [ ] サブスクリプションフォーム + プランが `subscription:` プレフィックスで出力される
- [ ] `decoratedPlans` JSON のプランが個別の products として展開される
- [ ] 旧版 Mammothology プラグイン残存があれば `legacy_plugins` に記録される
- [ ] `livemode_consistency` で本番/テスト混在を検出する
- [ ] `members_stats` に会員統計が含まれる

### WooCommerce
- [ ] 公開ステータスの商品のみ取得される
- [ ] 500件超のサイトは `woocommerce_products_truncated` warning が出る
- [ ] 決済ゲートウェイ一覧が `enabled_payment_gateways` に出る

### WC Vendors
- [ ] デフォルトコミッション率が `default-commission` として出力される
- [ ] ベンダー数が `vendor_count` に正しく入る
- [ ] Pro / Stripe Connect アドオンの有無が `pro_active` / `stripe_connect_active` に出る

### WP Crowdfunding
- [ ] crowdfunding タイプの商品が `crowdfunding_project` として出力される
- [ ] `goal_amount` / `raised_amount` / `min_amount` が含まれる
- [ ] リワード情報が `rewards` に含まれる

### BankPay
- [ ] 月額のみ・年額のみ・両方のサイトで適切な数の products が出る
- [ ] `entrance_flag` の値で `entrance_fee` が正しく反映される
- [ ] 月額0/年額0でも入会金がある場合、入会金単独で1件 products が出る
- [ ] **振込先口座情報の中身が応答に含まれない** (重要)
- [ ] `paid_users_count` が現実の会員数と一致する

### 統合動作
- [ ] 複数実装の同居サイト (UM+WPFullStripe+BankPay等) で全部の implementation が出る
- [ ] 1つの inspector で例外が出ても、他のinspectorは正常に動作する
- [ ] `summary.total_products` と `products` 配列の長さが一致する
- [ ] `summary.has_subscriptions` がサブスクリプション系商品があるサイトで true

---

## トラブルシューティング

| 症状 | 原因と対処 |
|---|---|
| `/inspect/membership` が 500 エラー | エラーログを確認。クラスの読込順 (membership/base が他より先) が間違っている可能性 |
| WP Full Stripe inspector が空応答 | テーブル名のプレフィックスが違う可能性 (`wp_fullstripe_*` 期待)。`metadata.wp-full-stripe.tables_present` を確認 |
| BankPay の paid_users_count が0 | `wp_bkp_user_info` テーブルのカラム名が違う、もしくはテーブル不在。`metadata.bankpay.tables_present` を確認 |
| WooCommerce 応答が遅い | 商品数が500件超の可能性。`warnings` の `woocommerce_products_truncated` で確認 |
| `legacy_plugins` が空でも管理画面で旧版が見える | プラグインフォルダが存在しないが、Mammothology以外のプラグインの場合は `LEGACY_PLUGIN_FILES` 定数の追加が必要 |

---

## 次のステップ

Phase 3 が問題なく動作したら、Phase 4 (Stripe inspector) に進みます。

**Phase 4 着手前に必要な調査**:

WP Full Stripe (Themeisle版) のStripe鍵保存先オプション名を WP-CLI で調査:

```bash
wp option list --search="*Stripe*" --format=table | head -20
wp option list --search="*fullstripe*" --format=table | head -20
wp option list --search="*wpfs*" --format=table | head -20
```

調査結果を共有いただければ、Phase 4 では以下を実装します:
- `WPSG_Inspect_Stripe_Detector` (5種の決済実装を識別)
- `WPSG_Stripe_WC_Stripe_Gateway` (WooCommerce Stripe Gateway)
- `WPSG_Stripe_WC_Vendors_Stripe_Connect`
- `WPSG_Stripe_WP_Full_Stripe`
- `WPSG_Stripe_PayPal_Plugins`
- `WPSG_Stripe_BankPay` (Stripe非利用、振込先設定確認のみ)
- `/inspect/stripe` エンドポイント
- 機微情報マスク (Publishable Key先頭8文字、Secret Keyは設定有無のみ)
