<?php
// CLI only: the module directory is reachable through the web server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Puts the shared mTLS client certificate into the module configuration
// (module.update), after checking it the same way the pairing page will.
// Other config keys are kept.
//
//     php tools/set_client_cert.php <frontend url> <admin token file> <cert.p12|cert+key.pem> [password] [hosts]
//     php tools/set_client_cert.php <frontend url> <admin token file> --remove
class APP {}
require_once __DIR__.'/../includes/Pairing.php';
use Modules\ZbxViewConnect\Includes\Pairing;

[$self, $base, $token_file] = $argv + [null, null, null];
if ($base === null || $token_file === null || !isset($argv[3])) {
	fwrite(STDERR, "usage: php $self <frontend url> <admin token file> <cert file>|--remove [password] [hosts]\n");
	exit(1);
}
$token = trim(file_get_contents($token_file));
$api = function (string $method, array $params) use ($base, $token) {
	$ctx = stream_context_create(['http' => ['method' => 'POST',
		'header' => "Content-Type: application/json-rpc\r\nAuthorization: Bearer $token",
		'content' => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1])],
		'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
	$out = json_decode(file_get_contents(rtrim($base, '/').'/api_jsonrpc.php', false, $ctx), true);
	if (isset($out['error'])) { fwrite(STDERR, json_encode($out['error'])."\n"); exit(2); }
	return $out['result'];
};

$module = $api('module.get', ['output' => ['moduleid', 'config'], 'filter' => ['id' => 'zbxviewconnect']])[0] ?? null;
if ($module === null) { fwrite(STDERR, "Module zbxviewconnect is not registered on $base.\n"); exit(2); }
$config = is_array($module['config']) ? $module['config'] : [];

if ($argv[3] === '--remove') {
	$config['client_cert'] = $config['client_cert_password'] = $config['client_cert_hosts'] = '';
}
else {
	$bytes = file_get_contents($argv[3]);
	// PEM stays text; a PKCS#12 bundle goes in as base64.
	$raw = strpos($bytes, '-----BEGIN') !== false ? $bytes : base64_encode($bytes);
	$check = Pairing::clientCertFrom($raw, $argv[4] ?? '', $argv[5] ?? '');
	if (isset($check['error'])) { fwrite(STDERR, "Certificate rejected: {$check['error']}\n"); exit(3); }
	echo "Certificate CN={$check['subject']}, valid to ", date('Y-m-d', $check['valid_to']),
		", hosts: ", $check['ch'] !== '' ? $check['ch'] : '(server host)', "\n";
	$config['client_cert'] = $raw;
	$config['client_cert_password'] = $argv[4] ?? '';
	$config['client_cert_hosts'] = $argv[5] ?? '';
}

$api('module.update', [['moduleid' => $module['moduleid'], 'config' => $config]]);
echo "Module config updated.\n";
