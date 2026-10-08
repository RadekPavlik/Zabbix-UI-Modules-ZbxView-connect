<?php
// CLI only: the module directory is reachable through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Runs the pairing flow of ConnectCreate against a live Zabbix, as the token's
// user: first pairing, a reload (must NOT create a token), "Pair again" (new
// token, old one deleted). Checks every link, then deletes what it created.
// Refuses to run when the user already has ZbxView tokens (a real phone).
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
if (Pairing::tokens($me['userid'])) { fwrite(STDERR, "User {$me['username']} already has ZbxView tokens - not touching them.\n"); exit(2); }

$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) { echo ($c ? 'PASS  ' : 'FAIL  '), $m, "\n"; if (!$c) $fail++; };
$run = function (int $repair) {
	$c = (new ReflectionClass('Modules\ZbxViewConnect\Actions\ConnectCreate'))->newInstanceWithoutConstructor();
	(new ReflectionProperty('CController', 'input'))->setValue($c, ['repair' => (string) $repair]);
	(new ReflectionMethod($c, 'doAction'))->invoke($c);
	return json_decode($c->getResponse()->getData()['main_block'], true);
};
$check = function (array $r) use ($ok, $CONFIG) {
	$q = [];
	parse_str(parse_url($r['link'] ?? '', PHP_URL_QUERY) ?? '', $q);
	$ok(str_starts_with($r['link'] ?? '', 'zbxview://add?') && $q['url'] === rtrim($CONFIG['url'], '/')
		&& $q['name'] === 'Test Zabbix' && $q['auth'] === 'token' && strlen($q['token'] ?? '') === 64
		&& str_ends_with($q['token'], $r['tail']), 'link carries url, name and the new token');
	$cert = Pairing::certificate($q['url']);
	echo '      certificate: ', json_encode($cert), ', pin in code: ', $q['pin'] ?? '(none)', "\n";
	$ok($cert === null || $cert['trusted'] ? !isset($q['pin']) : ($q['pin'] ?? '') === $cert['sha256'],
		'pin only for an untrusted certificate, equal to its SHA-256');
	$parts = $r['parts'] ?? [];
	$joined = implode('', array_map(fn($p) => preg_replace('/^ZBXV1:[a-z0-9]+:\d+\/\d+:/', '', $p), $parts));
	if ($CONFIG['client_cert'] !== '') {
		$ok(count($parts) >= 2 && $joined === $r['link'] && isset($q['cc'], $q['ck']) && $q['ch'] === 'zabbix-app.example.com',
			'certificate in the link, split into '.count($parts).' parts that rejoin to it');
	}
	else {
		$ok($parts === [$r['link']], 'no certificate: one plain code');
	}
	$GLOBALS['LAST_PARTS'] = $parts;
	return $q['token'];
};

$r1 = $run(0);
$ok(isset($r1['link']), 'first open creates a code');
$check($r1);
$t1 = Pairing::tokens($me['userid']);
$ok(count($t1) === 1 && (int) $t1[0]['expires_at'] === 0, 'one token, never expires');

$r2 = $run(0);
$ok(($r2['paired'] ?? false) === true && !isset($r2['link']), 'reload while paired creates nothing');
$ok(count(Pairing::tokens($me['userid'])) === 1, 'still one token');

sleep(1); // token names carry the second
$r3 = $run(1);
$ok(isset($r3['link']) && ($r3['replaced'] ?? 0) === 1, 'pair again: new code, old token replaced');
$check($r3);
$t3 = Pairing::tokens($me['userid']);
$ok(count($t3) === 1 && $t3[0]['tokenid'] !== $t1[0]['tokenid'], 'exactly one token left, the new one');

if (getenv('ZVC_PARTS_OUT')) { file_put_contents(getenv('ZVC_PARTS_OUT'), implode("\n", $GLOBALS['LAST_PARTS'])."\n"); }
$left = array_column(Pairing::tokens($me['userid']), 'tokenid');
if ($left) { API::Token()->delete($left); }
echo 'cleanup: deleted ', count($left), " token(s)\n", $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
