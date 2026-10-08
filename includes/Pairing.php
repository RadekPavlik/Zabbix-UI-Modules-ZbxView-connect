<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Includes;

use API;
use APP;

/**
 * One pairing per user: the API token named "ZbxView <date time>" that the
 * app signs in with. It never expires; pairing again replaces it.
 * Server address, name and the self-signed flag come from the module
 * config (manifest "config", changeable by an admin), never from the user.
 */
class Pairing {

	public const TOKEN_PREFIX = 'ZbxView ';

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
