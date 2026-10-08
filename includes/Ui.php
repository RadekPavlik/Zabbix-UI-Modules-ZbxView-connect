<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Includes;

use CObject;
use CWebUser;

/**
 * Small view helpers shared by both pages: the theme class and inline icons.
 */
class Ui {

	/**
	 * Root classes for the user's Zabbix theme: light (blue-theme, hc-light)
	 * or dark (dark-theme, hc-dark), plus zvc-hc for the high-contrast ones.
	 */
	public static function themeClass(): string {
		$theme = function_exists('getUserTheme') ? (string) getUserTheme(CWebUser::$data) : 'blue-theme';
		$dark = in_array($theme, ['dark-theme', 'hc-dark'], true);

		return 'zvc-theme-'.($dark ? 'dark' : 'light').(strpos($theme, 'hc-') === 0 ? ' zvc-hc' : '');
	}

	private const ICONS = [
		'qr' => '<path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3z"/><path d="M14 14h3v3h-3zM18 18h3v3h-3zM14 19h2M19 14h2"/>',
		'warn' => '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17h.01"/>',
		'timer' => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M9 2h6"/>',
		'eye_off' => '<path d="M3 3l18 18M10.6 6.1A9.8 9.8 0 0 1 12 6c5 0 9 6 9 6a17 17 0 0 1-3.2 3.8M6.6 6.6C4.3 8 3 12 3 12s4 6 9 6a8.6 8.6 0 0 0 4-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
		'external' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		'file_ok' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5M9 14l2 2 4-4"/>',
		'file' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5"/>',
		'file_bad' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5M12 11v3M12 17h.01"/>',
		'refresh' => '<path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/>',
		'phone' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
		'plus' => '<path d="M12 5v14M5 12h14"/>',
		'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>'
	];

	/**
	 * An inline SVG icon (stroke = currentColor) as a raw, unescaped item.
	 */
	public static function icon(string $name): CObject {
		return new CObject('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
			.' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.(self::ICONS[$name] ?? '').'</svg>');
	}
}
