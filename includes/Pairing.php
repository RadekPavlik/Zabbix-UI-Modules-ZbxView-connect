<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Includes;

use API;
use APP;

/**
 * One API token per phone ("ZbxView · <device>") that the app signs in
 * with. It never expires once a phone has used it; a code nobody scanned
 * within the pairing window is dropped again (see settle()).
 * Server address, name and the self-signed flag come from the module
 * config (manifest "config", changeable by an admin), never from the user.
 */
class Pairing {

	public const TOKEN_PREFIX = 'ZbxView ';

	/** Seconds a new code waits for its phone; the page hides it then. */
	public const PAIR_WINDOW = 300;

	/** settle() drops unused tokens this much older than the window. */
	private const SWEEP_SLACK = 60;

	/** Marks the new token of a re-paired device until its old one is gone. */
	public const REPAIR_MARK = ' ~';

	/**
	 * The user's ZbxView tokens, newest first.
	 */
	public static function tokens(string $userid): array {
		$tokens = API::Token()->get([
			'output' => ['tokenid', 'name', 'created_at', 'lastaccess', 'status', 'expires_at'],
			'userids' => [$userid],
			'search' => ['name' => self::TOKEN_PREFIX],
			'startSearch' => true
		]);

		if (!is_array($tokens)) {
			return [];
		}

		usort($tokens, static fn($a, $b) => (int) $b['created_at'] <=> (int) $a['created_at']);

		return $tokens;
	}

	/** Token names of a device: "ZbxView · <device>"; 1.x used "ZbxView <date>". */
	public const DEVICE_SEPARATOR = '· ';

	/**
	 * One of the user's ZbxView tokens by id, or null.
	 */
	public static function token(string $userid, string $tokenid): ?array {
		foreach (self::tokens($userid) as $token) {
			if ((string) $token['tokenid'] === $tokenid) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * A token no phone has used yet - a code still waiting to be scanned.
	 */
	public static function isPending(array $token): bool {
		return (int) $token['lastaccess'] === 0;
	}

	/**
	 * The device a repair token stands in for ("<device> ~", "<device> ~ (2)"),
	 * or null for an ordinary token.
	 */
	public static function repairOf(array $token): ?string {
		$rest = substr((string) $token['name'], strlen(self::TOKEN_PREFIX.self::DEVICE_SEPARATOR));

		return strpos((string) $token['name'], self::TOKEN_PREFIX.self::DEVICE_SEPARATOR) === 0
				&& preg_match('/^(.+)'.preg_quote(self::REPAIR_MARK, '/').'(?: \(\d+\))?$/', $rest, $m)
			? $m[1]
			: null;
	}

	/**
	 * The user's paired devices (one ZbxView token each that a phone has
	 * used), newest first: tokenid, name (device), created, lastaccess (unix),
	 * active. Codes still waiting to be scanned are not devices.
	 */
	public static function devices(string $userid, string $legacy_label = 'Phone'): array {
		return self::devicesOf(self::tokens($userid), $legacy_label);
	}

	private static function devicesOf(array $tokens, string $legacy_label): array {
		$devices = [];

		foreach ($tokens as $token) {
			if (self::isPending($token) || self::repairOf($token) !== null) {
				continue;
			}

			$rest = substr((string) $token['name'], strlen(self::TOKEN_PREFIX));
			$name = strpos($rest, self::DEVICE_SEPARATOR) === 0
				? substr($rest, strlen(self::DEVICE_SEPARATOR))
				: $legacy_label.' ('.substr($rest, 0, 10).')';

			$devices[] = [
				'tokenid' => (string) $token['tokenid'],
				'name' => $name,
				'created' => (int) $token['created_at'],
				'lastaccess' => (int) $token['lastaccess'],
				'active' => (int) $token['status'] === ZBX_AUTH_TOKEN_ENABLED
					&& ((int) $token['expires_at'] === 0 || (int) $token['expires_at'] > time())
			];
		}

		return $devices;
	}

	/**
	 * What the phones did since the page last looked:
	 * - a repair token a phone has used replaces the old token of its device
	 *   (old one deleted, new one takes the device's name);
	 * - codes nobody scanned within the window (plus a little slack for a scan
	 *   at the last second) are dropped, so an unpaired device never lingers.
	 * Returns ['paired' => [tokenid => device], 'dropped' => [tokenid, ...]].
	 */
	public static function settle(string $userid, string $legacy_label = 'Phone'): array {
		$tokens = self::tokens($userid);
		$deadline = time() - self::PAIR_WINDOW - self::SWEEP_SLACK;
		$dropped = [];

		foreach ($tokens as $token) {
			if (self::isPending($token) && (int) $token['created_at'] < $deadline) {
				$dropped[] = (string) $token['tokenid'];
			}
		}

		if ($dropped) {
			API::Token()->delete($dropped);
			$tokens = array_values(array_filter($tokens,
				static fn($t) => !in_array((string) $t['tokenid'], $dropped, true)
			));
		}

		$paired = [];
		$devices = self::devicesOf($tokens, $legacy_label);

		foreach ($tokens as $token) {
			$device = self::repairOf($token);

			if ($device === null || self::isPending($token)) {
				continue;
			}

			// The phone scanned the new code: only now its old token goes.
			foreach ($devices as $d) {
				if ($d['name'] === $device && $d['tokenid'] !== (string) $token['tokenid']) {
					API::Token()->delete([$d['tokenid']]);
				}
			}

			API::Token()->update(['tokenid' => $token['tokenid'], 'name' => self::tokenName($userid, $device)]);
			$paired[(string) $token['tokenid']] = $device;
		}

		return ['paired' => $paired, 'dropped' => $dropped];
	}

	/**
	 * A token name for $device that no token of the user has yet; $mark is
	 * kept whole when the device name has to be cut.
	 */
	public static function tokenName(string $userid, string $device, string $mark = ''): string {
		$device = trim(preg_replace('/\s+/', ' ', $device));
		$taken = array_column(self::tokens($userid), 'name');
		// Zabbix token names are at most 64 characters.
		$base = self::TOKEN_PREFIX.self::DEVICE_SEPARATOR.mb_substr($device, 0, 48 - mb_strlen($mark)).$mark;
		$name = $base;

		for ($i = 2; in_array($name, $taken, true); $i++) {
			$name = $base.' ('.$i.')';
		}

		return $name;
	}

	/**
	 * The token the app is (most likely) using: newest enabled, unexpired one.
	 */
	public static function active(string $userid): ?array {
		foreach (self::tokens($userid) as $token) {
			if ((int) $token['status'] === ZBX_AUTH_TOKEN_ENABLED
					&& ((int) $token['expires_at'] === 0 || (int) $token['expires_at'] > time())) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Address, name and certificate flag the QR code carries.
	 */
	public static function server(bool $probe = true): array {
		global $ZBX_SERVER_NAME;

		$module = APP::ModuleManager()->getModule('zbxviewconnect');
		$config = self::withDefaults($module !== null ? $module->getConfig() : []);

		$url = trim((string) ($config['url'] ?? ''));
		$name = trim((string) ($config['name'] ?? ''));

		$url = rtrim($url !== '' ? $url : self::frontendUrl(), '/');

		// self_signed: "auto" (default) = look at the certificate; "1" = always
		// pin; "0" = never (public CA, e.g. behind Cloudflare).
		$mode = strtolower(trim((string) ($config['self_signed'] ?? 'auto')));
		// $probe=false: for display only - no connection to the address.
		$cert = !$probe || in_array($mode, ['0', 'false', 'no'], true) ? null : self::certificate($url);
		$pin = '';

		if ($cert !== null && ($mode !== 'auto' || !$cert['trusted'])) {
			$pin = $cert['sha256'];
		}

		return [
			'url' => $url,
			'name' => $name !== '' ? $name
				: (isset($ZBX_SERVER_NAME) && $ZBX_SERVER_NAME !== '' ? $ZBX_SERVER_NAME : 'Zabbix'),
			'self_signed' => $pin !== '' || in_array($mode, ['1', 'true', 'yes'], true),
			// SHA-256 of the certificate DER, lower-case hex - the same value the
			// app stores as its certificate pin, so it trusts exactly this
			// certificate from the first request on.
			'pin' => $pin
		];
	}

	/**
	 * The module config with manifest defaults for empty keys.
	 */
	public static function config(): array {
		$module = APP::ModuleManager()->getModule('zbxviewconnect');

		return self::withDefaults($module !== null ? $module->getConfig() : []);
	}

	/**
	 * A clientCertFrom() result for people: set / error code / CN, validity
	 * and the hosts it goes to (the server's host when none are named).
	 */
	public static function describe(array $cert, string $url = ''): array {
		if ($cert === []) {
			return ['set' => false];
		}

		if (isset($cert['error'])) {
			return ['set' => true, 'error' => $cert['error']];
		}

		$hosts = $cert['ch'] !== '' ? $cert['ch'] : (string) (parse_url($url !== '' ? $url : self::frontendUrl(),
			PHP_URL_HOST) ?? '');

		return [
			'set' => true,
			'curve' => $cert['curve'] ?? '',
			'cn' => $cert['subject'],
			'valid_to' => $cert['valid_to'] > 0 ? date('Y-m-d', $cert['valid_to']) : '',
			'expired' => $cert['valid_to'] > 0 && $cert['valid_to'] < time(),
			'hosts' => str_replace(',', ', ', $hosts)
		];
	}

	/**
	 * Config saved in Zabbix wins; a key it lacks (module registered by an
	 * older version, or never configured) falls back to manifest.json.
	 */
	private static function withDefaults(array $config): array {
		$file = @file_get_contents(__DIR__.'/../manifest.json');
		$manifest = $file !== false ? json_decode($file, true) : null;
		$defaults = is_array($manifest) && is_array($manifest['config'] ?? null) ? $manifest['config'] : [];

		foreach ($defaults as $key => $value) {
			if (!array_key_exists($key, $config) || trim((string) $config[$key]) === '') {
				$config[$key] = $value;
			}
		}

		return $config;
	}

	/**
	 * The certificate the server presents at $url: its SHA-256 fingerprint and
	 * whether this server's CA store trusts it. Null for http:// or when the
	 * address cannot be reached from here.
	 */
	public static function certificate(string $url): ?array {
		$parts = parse_url($url);

		if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
			return null;
		}

		$host = $parts['host'];
		$target = 'ssl://'.$host.':'.(int) ($parts['port'] ?? 443);

		$open = static function (bool $verify) use ($host, $target) {
			$context = stream_context_create(['ssl' => [
				'capture_peer_cert' => true,
				'verify_peer' => $verify,
				'verify_peer_name' => $verify,
				'peer_name' => $host,
				'SNI_enabled' => true
			]]);
			$socket = @stream_socket_client($target, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);

			if ($socket === false) {
				return null;
			}

			$params = stream_context_get_params($socket);
			fclose($socket);

			return $params['options']['ssl']['peer_certificate'] ?? null;
		};

		$trusted = true;
		$cert = $open(true);

		if ($cert === null) {
			$trusted = false;
			$cert = $open(false);
		}

		if ($cert === null || !openssl_x509_export($cert, $pem)) {
			return null;
		}

		$b64 = preg_replace('/-----[^-]+-----|\s+/', '', $pem);

		return [
			'trusted' => $trusted,
			'sha256' => hash('sha256', base64_decode($b64))
		];
	}

	/**
	 * The shared mTLS client certificate from the module config, compact for
	 * the code: certificate DER and PKCS#8 key DER as base64url, plus the
	 * hosts it is sent to. [] when none is configured; ['error' => code] when
	 * one is configured but unusable.
	 *
	 * config.client_cert: PEM with certificate and key, or base64 of a
	 * PKCS#12 bundle / of such PEM; client_cert_password; client_cert_hosts
	 * (comma separated, empty = the server's host).
	 *
	 * Only EC keys: an RSA certificate with its key does not fit even into
	 * three readable codes.
	 */
	public static function clientCert(): array {
		$module = APP::ModuleManager()->getModule('zbxviewconnect');
		$config = self::withDefaults($module !== null ? $module->getConfig() : []);

		return self::clientCertFrom(
			(string) ($config['client_cert'] ?? ''),
			(string) ($config['client_cert_password'] ?? ''),
			(string) ($config['client_cert_hosts'] ?? '')
		);
	}

	public static function clientCertFrom(string $raw, string $password, string $hosts): array {
		$raw = trim(str_replace('\\n', "\n", $raw));

		if ($raw === '') {
			return [];
		}

		if (strpos($raw, '-----BEGIN') === false) {
			$bin = base64_decode(preg_replace('/\s+/', '', $raw), true);

			if ($bin === false) {
				return ['error' => 'invalid'];
			}

			if (strpos($bin, '-----BEGIN') !== false) {
				$raw = $bin;
			}
			elseif (openssl_pkcs12_read($bin, $p12, $password)) {
				$raw = $p12['cert'].$p12['pkey'];
				$password = '';
			}
			else {
				return ['error' => 'password'];
			}
		}

		$cert = @openssl_x509_read($raw);
		$key = @openssl_pkey_get_private($raw, $password);

		if ($cert === false) {
			return ['error' => 'no_cert'];
		}

		if ($key === false) {
			return ['error' => preg_match('/ENCRYPTED/', $raw) ? 'password' : 'no_key'];
		}

		if (!openssl_x509_check_private_key($cert, $key)) {
			return ['error' => 'mismatch'];
		}

		$details = openssl_pkey_get_details($key);

		if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
			return ['error' => 'not_ec'];
		}

		$der = static function (string $pem): string {
			return base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
		};
		$url64 = static fn(string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

		openssl_x509_export($cert, $cert_pem);
		// OpenSSL 3 exports PKCS#8 ("BEGIN PRIVATE KEY"), what the app expects.
		openssl_pkey_export($key, $key_pem);

		$list = array_values(array_filter(array_map(
			static fn($h) => strtolower(trim((string) $h)), preg_split('/[,;\s]+/', $hosts)
		), static fn($h) => $h !== ''));

		$curve = (string) ($details['ec']['curve_name'] ?? '');
		$curve_names = ['prime256v1' => 'P-256', 'secp384r1' => 'P-384', 'secp521r1' => 'P-521'];

		return [
			'curve' => $curve_names[$curve] ?? $curve,
			'cc' => $url64($der($cert_pem)),
			'ck' => $url64($der($key_pem)),
			'ch' => implode(',', $list),
			'subject' => (string) (openssl_x509_parse($cert)['subject']['CN'] ?? ''),
			'valid_to' => (int) (openssl_x509_parse($cert)['validTo_time_t'] ?? 0)
		];
	}

	/**
	 * The zbxview://add link for $server (Pairing::server()), $token and the
	 * client certificate (clientCert() result, may be []).
	 */
	public static function link(array $server, string $token, array $cert): string {
		return 'zbxview://add?'.http_build_query([
			'url' => $server['url'],
			'name' => $server['name'],
			'auth' => 'token',
			'token' => $token,
			'self_signed' => $server['self_signed'] ? '1' : '0'
		] + (($server['pin'] ?? '') !== '' ? ['pin' => $server['pin']] : [])
		+ ($cert && !isset($cert['error']) ? array_filter([
			'cc' => $cert['cc'],
			'ck' => $cert['ck'],
			'ch' => $cert['ch']
		], 'strlen') : []), '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * A link too big for one readable QR code as [count] parts the page shows
	 * in turn: "ZBXV1:<id>:<index>/<count>:<chunk>". A link that fits stays
	 * one plain code. ~400 characters per part keeps every code at the size
	 * that scanned reliably in tests (77x77 modules).
	 */
	public static function split(string $link, int $max = 400): array {
		if (strlen($link) <= $max + 200) {
			return [$link];
		}

		$count = (int) ceil(strlen($link) / $max);
		$size = (int) ceil(strlen($link) / $count);
		$id = substr(str_shuffle('abcdefghijkmnpqrstuvwxyz23456789'), 0, 6);
		$parts = [];

		for ($i = 0; $i < $count; $i++) {
			$parts[] = 'ZBXV1:'.$id.':'.($i + 1).'/'.$count.':'.substr($link, $i * $size, $size);
		}

		return $parts;
	}

	/**
	 * This frontend's address as the browser reached it, e.g.
	 * https://zabbix.example.com/zabbix - used when config.url is empty.
	 */
	public static function frontendUrl(): string {
		$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
			|| strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
		$host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
		$host = trim(explode(',', $host)[0]);
		$path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

		return ($https ? 'https' : 'http').'://'.$host.$path;
	}
}
