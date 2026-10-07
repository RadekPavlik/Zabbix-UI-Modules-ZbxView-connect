<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Includes;

use CWebUser;

/**
 * Lightweight per-module translation, mirroring the Topology Map Editor module:
 * flat "key: 'value'" YAML files in translation/messages.<lang>.yaml, picked by
 * the current user's language with an en_US fallback.
 */
class Lang {

	private static ?array $messages = null;

	private static function normalizeLang(string $lang): string {
		$lang = preg_replace('/[.@].*$/', '', $lang);
		$lang = str_replace('-', '_', $lang);

		if (preg_match('/^[a-z]{2}_[a-z]{2}$/i', $lang)) {
			return strtolower(substr($lang, 0, 2)).'_'.strtoupper(substr($lang, 3, 2));
		}

		return $lang;
	}

	private static function parse(string $path): array {
		$result = [];

		if (!is_readable($path)) {
			return $result;
		}

		$lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];

		foreach ($lines as $line) {
			$trimmed = trim($line);

			if ($trimmed === '' || $trimmed[0] === '#') {
				continue;
			}

			if (!preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $trimmed, $m)) {
				continue;
			}

			$value = $m[2];

			if (strlen($value) >= 2
					&& (($value[0] === "'" && substr($value, -1) === "'")
						|| ($value[0] === '"' && substr($value, -1) === '"'))) {
				$value = substr($value, 1, -1);
			}

			$result[$m[1]] = $value;
		}

		return $result;
	}

	private static function load(): array {
		if (self::$messages !== null) {
			return self::$messages;
		}

		$lang = isset(CWebUser::$data['lang']) ? self::normalizeLang((string) CWebUser::$data['lang']) : 'en_US';

		$path = __DIR__.'/../translation/messages.'.$lang.'.yaml';

		if (!is_readable($path)) {
			$path = __DIR__.'/../translation/messages.en_US.yaml';
		}

		self::$messages = self::parse($path);

		return self::$messages;
	}

	public static function t(string $key, string $fallback = ''): string {
		$messages = self::load();

		if (array_key_exists($key, $messages) && $messages[$key] !== '') {
			return $messages[$key];
		}

		return $fallback !== '' ? $fallback : $key;
	}
}
