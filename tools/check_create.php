<?php
// CLI only: the module directory is reachable through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Runs the pairing flow of ConnectCreate against a live Zabbix, as the token's
// user: a code is pending until "the phone" uses its token (done here with a
// user.get as that token), discard drops an unused one, a repair keeps the
// old token until the new one is used. Checks every link, then deletes what
// it created. Touches only devices it creates itself; others (a real phone)
// stay as they are. (The 6-minute sweep of stale codes is not waited for.)
//
//     php tools/check_create.php https://zabbix/zabbix ~/.config/zabbix/token [config-url]
class CWebUser {
	public static $data = ['lang' => 'en_US'];
	public static function get($k) { return self::$data[$k] ?? null; }
	public static function checkAccess($rule) { return true; }
	public static function isGuest() { return false; }
}
class _Live {
	public function __construct(private string $api) {}
	public function __call($method, $args) {
		global $BASE, $TOK;
		$ctx = stream_context_create(['http' => ['method' => 'POST',
			// checkAuthentication takes the token in its params, not the header.
			'header' => "Content-Type: application/json-rpc"
				.($method === 'checkAuthentication' ? '' : "\r\nAuthorization: Bearer $TOK"),
			'content' => json_encode(['jsonrpc' => '2.0', 'method' => strtolower($this->api).'.'.$method,
				'params' => $args[0], 'id' => 1])],
			'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
		$out = json_decode(file_get_contents("$BASE/api_jsonrpc.php", false, $ctx), true);
		if (isset($out['error'])) { fwrite(STDERR, json_encode($out['error'])."\n"); return false; }
		return $out['result'];
	}
}
class API { public static function __callStatic($api, $a) { return new _Live($api); } }
// Module config as an admin would set it (manifest "config").
class _Mod { public function getConfig() { global $CONFIG; return $CONFIG; } }
class _MM { public function getModule($id) { return new _Mod(); } }
class APP { public static function ModuleManager() { return new _MM(); } }

$BASE = rtrim($argv[1], '/');
$TOK = trim(file_get_contents($argv[2]));
$CONFIG = ['url' => $argv[3] ?? $BASE, 'name' => 'Test Zabbix', 'self_signed' => 'auto',
	// ZVC_CLIENT_CERT=<PEM file with cert+key> tests the certificate + parts path.
	'client_cert' => getenv('ZVC_CLIENT_CERT') ? file_get_contents(getenv('ZVC_CLIENT_CERT')) : '',
	'client_cert_hosts' => getenv('ZVC_CLIENT_CERT') ? 'zabbix-app.example.com' : ''];
require_once __DIR__.'/boot_common.php';
if (!function_exists('_x')) { function _x($s, $c) { return $s; } }
require_once '/usr/share/zabbix/include/translateDefines.inc.php';
$M = dirname(__DIR__);
require_once "$M/includes/Lang.php";
require_once "$M/includes/Pairing.php";
require_once "$M/actions/ConnectCreate.php";
use Modules\ZbxViewConnect\Includes\Pairing;

$me = API::User()->checkAuthentication(['token' => $TOK]);
CWebUser::$data['userid'] = $me['userid'];
// Devices this test did not create (e.g. a real phone) must stay untouched -
// after the sweep every page open does anyway (stale unused codes go).
Pairing::settle($me['userid']);
$before = Pairing::tokens($me['userid']);
$foreign = array_column($before, 'tokenid');
// "The phone scans the code": one API call as that token sets its lastaccess.
$use = function (string $link) use ($BASE): bool {
	$q = [];
	parse_str(parse_url($link, PHP_URL_QUERY) ?? '', $q);
	$ctx = stream_context_create(['http' => ['method' => 'POST',
		'header' => "Content-Type: application/json-rpc\r\nAuthorization: Bearer ".$q['token'],
		'content' => json_encode(['jsonrpc' => '2.0', 'method' => 'user.get', 'params' => ['output' => ['userid']], 'id' => 1])],
		'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
	$out = json_decode(file_get_contents("$BASE/api_jsonrpc.php", false, $ctx), true);
	return isset($out['result']);
};

$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) { echo ($c ? 'PASS  ' : 'FAIL  '), $m, "\n"; if (!$c) $fail++; };
$run = function (array $input) {
	$c = (new ReflectionClass('Modules\ZbxViewConnect\Actions\ConnectCreate'))->newInstanceWithoutConstructor();
	(new ReflectionProperty('CController', 'input'))->setValue($c, $input);
	(new ReflectionMethod($c, 'doAction'))->invoke($c);
	return json_decode($c->getResponse()->getData()['main_block'], true);
};
$check = function (array $r) use ($ok, $CONFIG) {
	$q = [];
	parse_str(parse_url($r['link'] ?? '', PHP_URL_QUERY) ?? '', $q);
	$ok(str_starts_with($r['link'] ?? '', 'zbxview://add?') && $q['url'] === rtrim($CONFIG['url'], '/')
		&& $q['name'] === 'Test Zabbix' && $q['auth'] === 'token' && strlen($q['token'] ?? '') === 64
		&& str_ends_with($q['token'], $r['tail']), 'link carries url, name and the new token');
	$parts = $r['parts'] ?? [];
	$joined = implode('', array_map(fn($p) => preg_replace('/^ZBXV1:[a-z0-9]+:\d+\/\d+:/', '', $p), $parts));
	if ($CONFIG['client_cert'] !== '') {
		$ok(count($parts) >= 2 && $joined === $r['link'] && isset($q['cc'], $q['ck']), 'certificate in the link, '.count($parts).' parts rejoin to it');
	}
	else {
		$ok($parts === [$r['link']], 'no certificate: one plain code');
	}
};
$mine = fn() => array_values(array_filter(Pairing::devices($me['userid']), fn($d) => !in_array($d['tokenid'], $GLOBALS['foreign'], true)));
$own = fn() => array_values(array_filter(Pairing::tokens($me['userid']), fn($t) => !in_array((string) $t['tokenid'], $GLOBALS['foreign'], true)));
$GLOBALS['foreign'] = $foreign;
$tag = 'claude-test-'.substr(md5((string) microtime(true)), 0, 5);

try {
	if ($before) {
		$r = $run(['mode' => 'first']);
		$ok(($r['paired'] ?? false) === true, 'mode=first with a device already paired creates nothing');
	}
	// A new code is pending: a token, but not a device yet.
	$r = $run(['mode' => 'add', 'device' => $tag.' ~']);
	$check($r);
	$t = Pairing::token($me['userid'], $r['tokenid']);
	$ok($t !== null && Pairing::isPending($t) && $mine() === [] && $r['device'] === $tag,
		'add: the code is pending - a token (repair mark stripped from the name), no device listed');
	$st = $run(['mode' => 'status', 'tokenid' => $r['tokenid']]);
	$ok(($st['paired'] ?? null) === false, 'status: not paired while nobody used the token');
	$d = $run(['mode' => 'discard', 'tokenid' => $r['tokenid']]);
	$st = $run(['mode' => 'status', 'tokenid' => $r['tokenid']]);
	$ok(($d['removed'] ?? false) === true && Pairing::token($me['userid'], $r['tokenid']) === null && ($st['gone'] ?? false) === true,
		'discard: the unused token is gone, status says so');

	// The phone scans: the token gets used, the device appears.
	$r = $run(['mode' => 'add', 'device' => $tag]);
	$check($r);
	$ok($use($r['link']), 'the token from the link signs in');
	$st = $run(['mode' => 'status', 'tokenid' => $r['tokenid']]);
	$d = $mine();
	$ok(($st['paired'] ?? null) === true && count($d) === 1 && $d[0]['name'] === $tag && $d[0]['tokenid'] === $r['tokenid'],
		'status after use: paired, device "'.$tag.'" listed');
	$dd = $run(['mode' => 'discard', 'tokenid' => $r['tokenid']]);
	$ok(($dd['removed'] ?? null) === false && count($mine()) === 1, 'discard of a used token does nothing');

	$r2 = $run(['mode' => 'add', 'device' => $tag]);
	$ok($use($r2['link']), 'second token signs in');
	$d = $mine();
	$ok(count($d) === 2 && in_array($tag.' (2)', array_column($d, 'name'), true), 'add with the same name: second device "'.$tag.' (2)"');
	$first = array_values(array_filter($d, fn($x) => $x['name'] === $tag))[0];
	$second = array_values(array_filter($d, fn($x) => $x['name'] !== $tag))[0];

	// Repair nobody scans: the device keeps its old token.
	$r = $run(['mode' => 'repair', 'tokenid' => $first['tokenid']]);
	$check($r);
	$t = Pairing::token($me['userid'], $r['tokenid']);
	$d = $mine();
	$ok($t !== null && Pairing::repairOf($t) === $tag && count($d) === 2
		&& in_array($first['tokenid'], array_column($d, 'tokenid'), true),
		'repair: the new token waits as "'.$tag.' ~", the old one still is the device');
	$run(['mode' => 'discard', 'tokenid' => $r['tokenid']]);
	$d = $mine();
	$ok(count($d) === 2 && in_array($first['tokenid'], array_column($d, 'tokenid'), true) && Pairing::token($me['userid'], $r['tokenid']) === null,
		'repair discarded: device as it was, the waiting token gone');

	// Repair the phone scans: old token out, new one takes the name.
	$r = $run(['mode' => 'repair', 'tokenid' => $first['tokenid']]);
	$ok($use($r['link']), 'the repair token signs in');
	$st = $run(['mode' => 'status', 'tokenid' => $r['tokenid']]);
	$d = $mine();
	$ids = array_column($d, 'tokenid');
	$ok(($st['paired'] ?? null) === true && ($st['device'] ?? null) === $tag && count($d) === 2
		&& in_array($tag, array_column($d, 'name'), true) && !in_array($first['tokenid'], $ids, true)
		&& in_array($r['tokenid'], $ids, true) && in_array($second['tokenid'], $ids, true)
		&& Pairing::token($me['userid'], $r['tokenid'])['name'] === Pairing::TOKEN_PREFIX.Pairing::DEVICE_SEPARATOR.$tag,
		'repair used: that device has the new token under its name, the old token is gone, the other device is untouched');

	$r = $run(['mode' => 'remove', 'tokenid' => $second['tokenid']]);
	$d = $mine();
	$ok(($r['removed'] ?? false) && count($d) === 1 && $d[0]['name'] === $tag, 'remove: only that device is gone');

	$r = $run(['mode' => 'remove', 'tokenid' => '1']);
	$ok(isset($r['error']), 'remove of a token that is not one of the user\'s devices is refused');
}
finally {
	$left = array_column($own(), 'tokenid');
	if ($left) { API::Token()->delete($left); }
	$after = array_column(Pairing::tokens($me['userid']), 'tokenid');
	sort($after); $f = $foreign; sort($f);
	$ok($after === $f, 'devices the test did not create are untouched ('.count($f).')');
	echo 'cleanup: deleted ', count($left), " token(s)\n", $fail ? "FAILED: $fail\n" : "ALL PASS\n";
}
exit($fail ? 1 : 0);
