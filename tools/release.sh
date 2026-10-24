#!/bin/bash
# WP Security Guard のリリース（署名つき）。★v2.10.0
#
#   使い方: tools/release.sh <版>      例: tools/release.sh 2.10.1
#
# 2.10.0 から、各サイトは更新を入れる前に zip の署名を確かめる。署名は集中管理側（WPSM）の
# 秘密鍵でしか作れないので、リリースは必ずこのスクリプトで行う（GitHub の画面から作らない）。
#
#   1. 見出しの Version とタグ v<版> が揃っていて、タグが GitHub に push 済みかを確かめる
#   2. タグの中身（コミット済みのもの）から配布 zip を作る（フォルダ名は wp-security-guard）
#   3. WPSM の鍵で署名し、同梱の公開鍵で確かめられることを手元で検証する
#   4. 「yes」と入力したときだけ、zip と署名を同時に載せてリリースを作る
#      ＝ここで全サイトへの自動配布が始まる（半日ほどで行き渡る）
#
# 注意：リリース前に、正常系を実際の WordPress で必ず通すこと（2.9.2 の事故を繰り返さない）。
set -euo pipefail

VER="${1:?版を指定してください（例: 2.10.1）}"
VER="${VER#v}"
TAG="v${VER}"
REPO="RemakeDevTeam/wp-security-guard"
cd "$(dirname "$0")/.."

PHP="${WPSM_PHP:-/Users/nomakatsumi/Library/Application Support/Local/lightning-services/php-8.3.30+1/bin/darwin-arm64/bin/php}"
SIGN_TOOL="${WPSM_SIGN_TOOL:-/Users/nomakatsumi/Documents/GitHub/wp-site-manager/tools/wpsg-sign-release.php}"
fail(){ echo "中止: $*" >&2; exit 1; }

# 1. 版とタグ
grep -q "^Version: ${VER}\$" wp-security-guard.php || fail "wp-security-guard.php の Version が ${VER} ではありません"
git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null || fail "タグ ${TAG} がありません（コミットしてタグを付けてください）"
git ls-remote --exit-code --tags origin "refs/tags/${TAG}" >/dev/null 2>&1 || fail "タグ ${TAG} が GitHub にありません（git push origin ${TAG}）"
gh release view "${TAG}" --repo "${REPO}" >/dev/null 2>&1 && fail "リリース ${TAG} は既にあります"

# 2. 配布 zip（コミット済みの中身から作る。手元の未コミットの変更は入らない）
OUT=$(mktemp -d); trap 'rm -rf "${OUT}"' EXIT
git archive --format=tar --prefix=wp-security-guard/ "${TAG}" | tar -x -C "${OUT}"
W="${OUT}/wp-security-guard"
rm -rf "${W}/.github" "${W}/docs" "${W}/tools" "${W}/build" "${W}/.gitattributes" "${W}/.gitignore"
rm -f "${W}"/PHASE*-VERIFICATION.md
ZIP="wp-security-guard-${TAG}-install.zip"
( cd "${OUT}" && zip -qrX "${ZIP}" wp-security-guard )
TOP=$(unzip -l "${OUT}/${ZIP}" | awk 'NR>3 {print $4}' | grep -v '^$' | cut -d/ -f1 | sort -u)
[ "${TOP}" = "wp-security-guard" ] || fail "zip の中のフォルダ名が wp-security-guard ではありません（${TOP}）"

# 3. 署名と検証（同梱の公開鍵＝zip の中の class-update-verify.php の値で確かめる）
"${PHP}" "${SIGN_TOOL}" sign "${OUT}/${ZIP}" "${VER}" 2>/dev/null | grep '^{' > "${OUT}/${ZIP}.sig" || fail "署名できませんでした"
"${PHP}" -r '
  $zip = $argv[1]; $sigf = $argv[2]; $cls = $argv[3]; $ver = $argv[4];
  $m = json_decode(file_get_contents($sigf), true);
  if (!is_array($m) || $m["version"] !== $ver) { fwrite(STDERR, "署名ファイルの版が違います\n"); exit(1); }
  if (!hash_equals($m["sha256"], hash_file("sha256", $zip))) { fwrite(STDERR, "sha256 が一致しません\n"); exit(1); }
  preg_match_all("/\x27([0-9a-f]{64})\x27/", file_get_contents($cls), $k);
  $msg = "wpsg-update-v1\n" . $ver . "\n" . $m["sha256"];
  foreach ($k[1] as $hex) { if (sodium_crypto_sign_verify_detached(base64_decode($m["sig"]), $msg, hex2bin($hex))) { echo "同梱の公開鍵で署名を確認しました（" . substr($hex, 0, 12) . "…）\n"; exit(0); } }
  fwrite(STDERR, "同梱の公開鍵で署名を確かめられません\n"); exit(1);
' "${OUT}/${ZIP}" "${OUT}/${ZIP}.sig" "${W}/includes/class-update-verify.php" "${VER}" || fail "署名の検証に失敗しました"

# 4. リリース（ここから全サイトへ配布が始まる）
NOTES=$(awk -v v="## ${VER}" '$0==v{f=1;next} /^## /{if(f)exit} f' CHANGELOG.md)
echo "----"
echo "リリース ${TAG}"
echo "  ${ZIP} ($(du -h "${OUT}/${ZIP}" | cut -f1)) と署名ファイルを載せます。"
echo "  作成した時点で、自動更新により全サイトへの配布が始まります。"
read -r -p "リリースを作成しますか？（yes と入力）: " ANS
[ "${ANS}" = "yes" ] || fail "取りやめました（何も作成していません）"
gh release create "${TAG}" "${OUT}/${ZIP}" "${OUT}/${ZIP}.sig" --repo "${REPO}" --title "${TAG}" --notes "${NOTES:-${TAG}}"
echo "作成しました: https://github.com/${REPO}/releases/tag/${TAG}"
