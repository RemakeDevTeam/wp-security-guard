<?php
// WP/CF7 スタブで WPSecurityGuard クラスの CF7 部分を実ファイルから読み込んで検査する
define('ABSPATH', '/tmp/');
$GLOBALS['hooks'] = array();
function add_filter($h, $cb, $p = 10, $a = 1) { $GLOBALS['hooks'][$h][] = $cb; return true; }
function add_action($h, $cb, $p = 10, $a = 1) { return add_filter($h, $cb, $p, $a); }
function apply_filters($h, $v) { return $v; }
function get_option($k, $d = false) { return $GLOBALS['wpsg_opts'] ?? $d; }
function wp_parse_args($a, $d) { return array_merge($d, (array) $a); }
function wp_unslash($v) { return $v; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function remove_action() {} function home_url($p='') { return 'https://x'.$p; } function is_admin(){return false;}
function wpcf7_mail_replace_tags($c) { return preg_replace_callback('/\[([\w-]+)\]/', function ($m) {
    $p = WPCF7_Submission::get_instance()->get_posted_data(); return isset($p[$m[1]]) ? $p[$m[1]] : ''; }, $c); }

class WPCF7_Validation { public $inv = array();
  function invalidate($ctx, $msg) { $n = is_array($ctx) ? $ctx['name'] : $ctx->name; if (!isset($this->inv[$n])) $this->inv[$n] = $msg; }
  function is_valid() { return empty($this->inv); } }
class FakeTag { public $name; public $type; function __construct($n, $t) { $this->name = $n; $this->type = $t; } }
class FakeForm { public $body; public $register;
  function __construct($b, $reg = true) { $this->body = $b; $this->register = $reg; }
  function prop($k) { return $this->body; }
  function scan_form_tags($cond) { if (!$this->register) return array();
    preg_match_all('/\[(\w+\*?)\s+([A-Za-z][-\w:.]*)/', $this->body, $m, PREG_SET_ORDER); $out = array();
    foreach ($m as $x) if (rtrim($x[1], '*') === $cond['type']) $out[] = new FakeTag($x[2], $x[1]); return $out; } }
class WPCF7_Submission { static $inst; public $posted; public $form; public $resp;
  static function get_instance() { return self::$inst; }
  function get_posted_data() { return $this->posted; } function get_contact_form() { return $this->form; }
  function set_response($r) { $this->resp = $r; } }

// 実ファイルからクラス定義だけを取り出す
$src = file_get_contents($argv[1]);
$start = strpos($src, 'class WPSecurityGuard {');
$end = strpos($src, "// プラグイン初期化");
eval(substr($src, $start, $end - $start));

$confirm = '[multiform your-name][multiform your-tel][multiform your-email][multiform address][multiform your-message][previous "戻る"][submit "送信する"][multistep step2 last_step send_email "/contact/thanks"]';
$single  = '[text* your-name][email* your-email][textarea* your-message][submit "送信"]';
$g = WPSecurityGuard::get_instance();
$fails = 0;
function check($label, $cond) { global $fails; echo ($cond ? 'OK  ' : 'NG  ') . $label . "\n"; if (!$cond) $fails++; }
function run($g, $form, $posted) { $s = new WPCF7_Submission(); $s->form = $form; $s->posted = $posted; WPCF7_Submission::$inst = $s;
  $r = new WPCF7_Validation(); $r = $g->validate_cf7_multistep_final($r, array()); return $r; }
function feedback($g) { return $g->cf7_multistep_feedback_response(array('status' => 'validation_failed', 'message' => 'generic'), array()); }

// フックが登録されているか
foreach (array('wpcf7_validate', 'wpcf7_feedback_response', 'wpcf7_submit', 'wpcf7_additional_mail', 'wpcf7_validate_textarea*') as $h)
  check("hook registered: $h", !empty($GLOBALS['hooks'][$h]));

// 1) 確認ステップへの空送信（今回の事象）
$r = run($g, new FakeForm($confirm), array('cf7msm-no-ss' => '1', 'cf7msm_options' => '{"last_step":1}', 'step2' => ''));
check('empty carryover is rejected', !$r->is_valid());
check('rejection message shown', feedback($g)['message'] === WPSecurityGuard::CF7_CARRYOVER_MESSAGE);

// 2) 正常な引き継ぎ
$g2 = (function () { $r = new ReflectionClass('WPSecurityGuard'); $p = $r->getProperty('cf7_multistep_rejection'); $p->setAccessible(true); return $p; })();
$g2->setValue($g, null);
$ok = array('your-name' => '山田太郎', 'your-tel' => '', 'your-email' => 'taro@example.jp', 'address' => '札幌市北区', 'your-message' => '商品の在庫について教えてください。');
$r = run($g, new FakeForm($confirm), $ok);
check('valid carryover passes', $r->is_valid());
check('feedback untouched when passed', feedback($g)['message'] === 'generic');

// 3) 引き継いだ本文が英語のみ／URL過多
$g2->setValue($g, null);
$r = run($g, new FakeForm($confirm), array_merge($ok, array('your-message' => 'Buy cheap SEO backlinks now')));
check('english-only carried message rejected', !$r->is_valid() && feedback($g)['message'] === '英語でのお問い合わせには対応しておりません');
$g2->setValue($g, null);
$r = run($g, new FakeForm($confirm), array_merge($ok, array('your-message' => 'ご確認ください https://a.example https://b.example https://c.example https://d.example')));
check('too many URLs in carried message rejected', !$r->is_valid());

// 4) 単段フォームは対象外（空でもこの検査では弾かない）
$g2->setValue($g, null);
$r = run($g, new FakeForm($single), array());
check('single-step form untouched', $r->is_valid());

// 5) multiform がタグ登録されていない場合も本文から拾う
$r = run($g, new FakeForm($confirm, false), array());
check('fallback regex detects multiform', !$r->is_valid());

// 6) 一部だけ空（メールは空）→ 検査は通すが、メール(2)は外す
$g2->setValue($g, null);
$partial = array('your-name' => '山田', 'your-email' => '', 'your-message' => '在庫を教えてください');
$r = run($g, new FakeForm($confirm), $partial);
check('partial carryover passes validation', $r->is_valid());
$mails = array('mail_2' => array('recipient' => '[your-email]', 'body' => 'x'));
check('mail_2 with empty recipient dropped', $g->drop_cf7_mail_without_recipient($mails, null) === array());
run($g, new FakeForm($confirm), $ok);
check('mail_2 with valid recipient kept', count($g->drop_cf7_mail_without_recipient($mails, null)) === 1);
check('mail_2 "Name <addr>" kept', count($g->drop_cf7_mail_without_recipient(array('mail_2' => array('recipient' => '山田 <taro@example.jp>')), null)) === 1);
check('mail_2 junk recipient dropped', $g->drop_cf7_mail_without_recipient(array('mail_2' => array('recipient' => 'abc')), null) === array());

// 7) 入力ステップ（textarea）の従来判定が変わっていないこと
$_POST = array('your-message' => 'hello world');
$v = $g->validate_cf7_text(new WPCF7_Validation(), new FakeTag('your-message', 'textarea*'));
check('input step: english rejected (unchanged)', !$v->is_valid());
$_POST = array('your-message' => 'こんにちは');
check('input step: 5 ja chars pass (unchanged)', $g->validate_cf7_text(new WPCF7_Validation(), new FakeTag('your-message', 'textarea*'))->is_valid());
$_POST = array('your-message' => 'こんにち');
check('input step: 4 ja chars rejected (unchanged)', !$g->validate_cf7_text(new WPCF7_Validation(), new FakeTag('your-message', 'textarea*'))->is_valid());
$_POST = array('your-name' => 'John');
check('input step: sender check off by default (unchanged)', $g->validate_cf7_text(new WPCF7_Validation(), new FakeTag('your-name', 'text*'))->is_valid());

// 8) 通常POST（JSなし）でも文言が出る
$g2->setValue($g, null);
run($g, new FakeForm($confirm), array());
$g->cf7_multistep_submit_response(null, array('status' => 'validation_failed'));
check('non-JS submit response set', WPCF7_Submission::get_instance()->resp === WPSecurityGuard::CF7_CARRYOVER_MESSAGE);

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
