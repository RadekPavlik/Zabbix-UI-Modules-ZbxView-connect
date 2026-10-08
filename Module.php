<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect;

use APP;
use CMenuItem;
use CRoleHelper;
use CWebUser;
use Modules\ZbxViewConnect\Includes\Lang;
use Zabbix\Core\CModule;

/**
 * "Mobile connect" under User settings, next to API tokens: the page
 * creates a token for the signed-in user and shows it as a QR code that the
 * mobile app scans to add this server.
 */
class Module extends CModule {

	public const VERSION = '1.3.3';

	public function init(): void {
		// Administration → Mobile configuration: module-wide settings and the client
		// certificate import, for super admins. (The phone icon after this
		// entry and after "Mobile connect" comes from assets/css/menu.css.)
		if (CWebUser::getType() == USER_TYPE_SUPER_ADMIN) {
			$admin = APP::Component()->get('menu.main')->find(_('Administration'));

			if ($admin !== null) {
				$admin->getSubMenu()->add(
					(new CMenuItem(Lang::t('admin_menu', 'Mobile configuration')))
						->setAction('zbxview.connect.settings')
				);
			}
		}

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
			(new CMenuItem(Lang::t('menu', 'Mobile connect')))->setAction('zbxview.connect')
		);
	}

	public function getAssets(): array {
		$assets = parent::getAssets();

		// Only on its own pages: the QR library has no business on every screen.
		$action = APP::Component()->router->getAction();

		if ($action === 'zbxview.connect.settings') {
			$assets['js'][] = 'qrcode.js?v='.self::VERSION;
			$assets['js'][] = 'settings.js?v='.self::VERSION;
			$assets['css'][] = 'connect.css?v='.self::VERSION;
		}

		if ($action === 'zbxview.connect') {
			$assets['js'][] = 'qrcode.js?v='.self::VERSION;
			$assets['js'][] = 'connect.js?v='.self::VERSION;
			$assets['css'][] = 'connect.css?v='.self::VERSION;
		}

		return $assets;
	}
}
