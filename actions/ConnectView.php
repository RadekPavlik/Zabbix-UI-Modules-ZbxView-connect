<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Actions;

use CController;
use CControllerResponseData;
use CCsrfTokenHelper;
use CProfile;
use CRoleHelper;
use CWebUser;
use Modules\ZbxViewConnect\Includes\Lang;

/**
 * The "Connect application" page: a short form (name, address the phone
 * reaches, token validity) whose result - a QR code - is drawn by
 * connect.js once ConnectCreate has made the token.
 */
class ConnectView extends CController {

	public const PROFILE_URL = 'web.zbxviewconnect.url';
	public const PROFILE_NAME = 'web.zbxviewconnect.name';

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest() && $this->checkAccess(CRoleHelper::ACTIONS_MANAGE_API_TOKENS);
	}

	protected function doAction(): void {
		global $ZBX_SERVER_NAME;

		$this->setResponse(new CControllerResponseData([
			'title' => Lang::t('title', 'Connect application'),
			'url' => CProfile::get(self::PROFILE_URL, self::frontendUrl()),
			'name' => CProfile::get(self::PROFILE_NAME,
				isset($ZBX_SERVER_NAME) && $ZBX_SERVER_NAME !== '' ? $ZBX_SERVER_NAME : 'Zabbix'
			),
			'user' => getUserFullname(CWebUser::$data),
			// A token is useless to the app when the role may not use the API.
			'api_access' => CWebUser::checkAccess('api.access'),
			'csrf' => CCsrfTokenHelper::get('zbxview.connect.create'),
			'tokens_url' => 'zabbix.php?action=user.token.list'
		]));
	}

	/**
	 * This frontend's address as the browser reached it, e.g.
	 * https://zabbix.example.com/zabbix - a starting point the user corrects
	 * when the phone goes through another address (relay, Cloudflare).
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
