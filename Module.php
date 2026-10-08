<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect;

use APP;
use CMenuItem;
use CRoleHelper;
use CWebUser;
use Modules\ZbxViewConnect\Includes\Lang;
use Zabbix\Core\CModule;

/**
 * "Connect application" under User settings, next to API tokens: the page
 * creates a token for the signed-in user and shows it as a QR code that the
 * mobile app scans to add this server.
 */
class Module extends CModule {

	public const VERSION = '1.0.1';

	public function init(): void {
		// Only users who may create their own API tokens get the entry - the
		// same rule that shows "API tokens" in the user menu.
		if (CWebUser::isGuest() || !CWebUser::checkAccess(CRoleHelper::ACTIONS_MANAGE_API_TOKENS)) {
			return;
		}

		$user_menu = APP::Component()->get('menu.user');
		$settings = $user_menu !== null ? $user_menu->find(_('User settings')) : null;

		if ($settings === null || $settings->getSubMenu() === null) {
			return;
		}

		$settings->getSubMenu()->add(
			(new CMenuItem(Lang::t('menu', 'Connect application')))->setAction('zbxview.connect')
		);
	}

	public function getAssets(): array {
		$assets = parent::getAssets();

		// Only on its own page: the QR library has no business on every screen.
		if (APP::Component()->router->getAction() === 'zbxview.connect') {
			$assets['js'][] = 'qrcode.js?v='.self::VERSION;
			$assets['js'][] = 'connect.js?v='.self::VERSION;
			$assets['css'][] = 'connect.css?v='.self::VERSION;
		}

		return $assets;
	}
}
