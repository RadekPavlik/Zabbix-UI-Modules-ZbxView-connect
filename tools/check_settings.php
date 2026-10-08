<?php
// CLI only: the module directory is reachable through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Runs SettingsUpdate (the admin page's save action) against a live Zabbix:
// module.get/update go to the real API as the token's user (super admin).
// Saves settings, imports a certificate (good / RSA / wrong password),
// removes it, and finally restores the module config it found.
//
//     php tools/check_settings.php https://zabbix/zabbix ~/.config/zabbix/token cert.pem cert.p12 p12-password rsa.pem
class CWebUser {
	public static $data = ['lang' => 'en_US'];
	public static function getType() { return 3; }
	public static function get($k) { return self::$data[$k] ?? null; }
}
// get_and_clear_messages() asks the DB-backed settings whether technical
// errors may be shown; outside the frontend there is no DB.
class CSettingsHelper {
	const SHOW_TECHNICAL_ERRORS = 'show_technical_errors';
	public static function getPublic($key) { return 1; }
}
function api(string $method, array $params) {
	global $BASE, $TOK;
	$ctx = stream_context_create(['http' => ['method' => 'POST',
		'header' => "Content-Type: application/json-rpc\r\nAuthorization: Bearer $TOK",
		'content' => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1])],
		'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
	$out = json_decode(file_get_contents("$BASE/api_jsonrpc.php", false, $ctx), true);
	if (isset($out['error'])) { fwrite(STDERR, json_encode($out['error'])."\n"); exit(2); }
	return $out['result'];
}
// The live module record stands in for CModule (getConfig / setConfig).
class _Mod {
	public function __construct(public string $id, public array $config) {}
	public function getConfig() { return $this->config; }
	public function setConfig(array $c) { $this->config = $c; api('module.update', [['moduleid' => $this->id, 'config' => $c]]); return $this; }
}
class APP {
	public static ?_Mod $m = null;
	public static function ModuleManager() { return new class { public function getModule($id) { return APP::$m; } }; }
}
[$self, $BASE, $tokf, $pem, $p12, $p12pw, $rsa] = $argv + array_fill(0, 7, null);
$BASE = rtrim($BASE, '/');
$TOK = trim(file_get_contents($tokf));
require_once __DIR__.'/boot_common.php';
if (!function_exists('_x')) { function _x($s, $c) { return $s; } }
require_once '/usr/share/zabbix/include/translateDefines.inc.php';
$M = dirname(__DIR__);
require_once "$M/includes/Lang.php";
require_once "$M/includes/Pairing.php";
require_once "$M/actions/SettingsUpdate.php";

$rec = api('module.get', ['output' => ['moduleid', 'config'], 'filter' => ['id' => 'zbxviewconnect']])[0];
$original = $rec['config'];
APP::$m = new _Mod($rec['moduleid'], is_array($original) ? $original : []);

$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) { echo ($c ? 'PASS  ' : 'FAIL  '), $m, "\n"; if (!$c) $fail++; };
$run = function (array $input) {
	$c = (new ReflectionClass('Modules\ZbxViewConnect\Actions\SettingsUpdate'))->newInstanceWithoutConstructor();
	(new ReflectionProperty('CController', 'input'))->setValue($c, $input);
	(new ReflectionMethod($c, 'doAction'))->invoke($c);
	return json_decode($c->getResponse()->getData()['main_block'], true);
};
$live = fn() => api('module.get', ['output' => ['config'], 'moduleids' => [$rec['moduleid']]])[0]['config'];

try {
	$r = $run(['section' => 'settings', 'url' => 'https://zabbix.example.com/', 'name' => 'Test', 'self_signed' => '0']);
	$c = $live();
	$ok(($r['ok'] ?? false) && $c['url'] === 'https://zabbix.example.com' && $c['name'] === 'Test' && $c['self_signed'] === '0',
		'settings saved (trailing slash trimmed)');
	$r = $run(['section' => 'settings', 'url' => 'ftp://x', 'name' => '', 'self_signed' => 'auto']);
	$ok(isset($r['error']) && $live()['url'] === 'https://zabbix.example.com', 'bad address refused, config untouched');

	$r = $run(['section' => 'certificate', 'cert' => base64_encode(file_get_contents($pem)), 'password' => '', 'hosts' => 'a.example.com']);
	$c = $live();
	$ok(($r['ok'] ?? false) && ($r['cert']['cn'] ?? '') !== '' && $r['cert']['hosts'] === 'a.example.com'
		&& strpos($c['client_cert'], '-----BEGIN') !== false, 'PEM certificate imported: CN '.($r['cert']['cn'] ?? '?').', valid to '.($r['cert']['valid_to'] ?? '?'));

	$r = $run(['section' => 'certificate', 'cert' => base64_encode(file_get_contents($p12)), 'password' => 'wrong', 'hosts' => '']);
	$ok(isset($r['error']) && $live()['client_cert_hosts'] === 'a.example.com', 'PKCS#12 with a wrong password refused: '.($r['error'] ?? ''));
	$r = $run(['section' => 'certificate', 'cert' => base64_encode(file_get_contents($rsa)), 'password' => '', 'hosts' => '']);
	$ok(isset($r['error']), 'RSA certificate refused: '.($r['error'] ?? ''));
	$r = $run(['section' => 'certificate', 'cert' => base64_encode(file_get_contents($p12)), 'password' => $p12pw, 'hosts' => '']);
	$c = $live();
	$ok(($r['ok'] ?? false) && strpos($c['client_cert'], '-----BEGIN') === false && $r['cert']['hosts'] === 'zabbix.example.com',
		'PKCS#12 imported (stored as base64), goes to the server host by default');

	$r = $run(['section' => 'remove_cert']);
	$c = $live();
	$ok(($r['ok'] ?? false) && $c['client_cert'] === '' && $r['cert'] === ['set' => false], 'certificate removed');
}
finally {
	api('module.update', [['moduleid' => $rec['moduleid'], 'config' => $original]]);
	echo 'restored config: ', json_encode($live()), "\n", $fail ? "FAILED: $fail\n" : "ALL PASS\n";
}
exit($fail ? 1 : 0);
