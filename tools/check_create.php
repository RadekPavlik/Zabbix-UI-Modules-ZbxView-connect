<?php
// CLI only: the module directory is reachable through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Runs ConnectCreate against a live Zabbix: API calls go to the real API as
// the token's user, the rest is the module's own code. Prints the response,
// checks the zbxview://add link, then deletes the token it created.
//
//     php tools/check_create.php https://zabbix/zabbix ~/.config/zabbix/token
class CWebUser {
	public static $data = ['lang' => 'en_US'];
	public static function get($k) { return self::$data[$k] ?? null; }
	public static function checkAccess($rule) { return true; }
	public static function isGuest() { return false; }
}
class CProfile {
	public static array $saved = [];
	public static function update($k, $v, $t) { self::$saved[$k] = $v; }
	public static function get($k, $d = null) { return self::$saved[$k] ?? $d; }
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
$BASE = rtrim($argv[1], '/');
$TOK = trim(file_get_contents($argv[2]));
require_once __DIR__.'/boot_common.php';
if (!function_exists('_x')) { function _x($s, $c) { return $s; } }
require_once '/usr/share/zabbix/include/translateDefines.inc.php';
$M = dirname(__DIR__);
require_once "$M/includes/Lang.php";
require_once "$M/actions/ConnectView.php";
require_once "$M/actions/ConnectCreate.php";

$me = API::User()->checkAuthentication(['token' => $TOK]);
CWebUser::$data['userid'] = $me['userid'];

$c = (new ReflectionClass('Modules\ZbxViewConnect\Actions\ConnectCreate'))->newInstanceWithoutConstructor();
(new ReflectionProperty('CController', 'input'))->setValue($c,
	['name' => 'Test Zabbix', 'url' => 'https://zabbix.example.com/zabbix/', 'days' => '30', 'self_signed' => '1']);
(new ReflectionMethod($c, 'doAction'))->invoke($c);
$r = json_decode($c->getResponse()->getData()['main_block'], true);
echo json_encode(array_merge($r, ['link' => preg_replace('/token=[^&]+/', 'token=<redacted>', $r['link'] ?? '')]),
	JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";

$q = [];
parse_str(parse_url($r['link'], PHP_URL_QUERY), $q);
$ok = str_starts_with($r['link'], 'zbxview://add?') && $q['url'] === 'https://zabbix.example.com/zabbix'
	&& $q['name'] === 'Test Zabbix' && $q['auth'] === 'token' && strlen($q['token']) === 64
	&& $q['self_signed'] === '1' && str_ends_with($q['token'], $r['tail']);
echo $ok ? "LINK OK\n" : "LINK BAD\n";
echo 'profile: ', json_encode(CProfile::$saved, JSON_UNESCAPED_SLASHES), "\n";

$t = API::Token()->get(['output' => ['tokenid', 'name', 'expires_at'], 'filter' => ['name' => $r['token_name']],
	'userids' => [$me['userid']]]);
echo 'created: ', json_encode($t), "\n";
if ($t) { API::Token()->delete(array_column($t, 'tokenid')); echo "deleted\n"; }
