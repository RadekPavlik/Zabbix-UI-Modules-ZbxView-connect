<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Actions;

use CController;
use CControllerResponseData;
use CCsrfTokenHelper;
use CRoleHelper;
use CWebUser;
use Modules\ZbxViewConnect\Includes\Lang;
use Modules\ZbxViewConnect\Includes\Pairing;
use Modules\ZbxViewConnect\Includes\Ui;

/**
 * The "Mobile connect" page: the user's paired devices (each with its own
 * token) - new code or remove per device, "Add another device". With no
 * device yet, connect.js pairs the first one right away.
 */
class ConnectView extends CController {

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
		$server = Pairing::server(false);
		$devices = [];

		foreach (Pairing::devices((string) CWebUser::$data['userid'], Lang::t('device_default', 'Phone')) as $d) {
			$devices[] = $d + [
				'created_text' => zbx_date2str(DATE_TIME_FORMAT, $d['created']),
				'lastaccess_text' => $d['lastaccess'] > 0
					? zbx_date2str(DATE_TIME_FORMAT, $d['lastaccess'])
					: Lang::t('never_used', 'not yet')
			];
		}

		$this->setResponse(new CControllerResponseData([
			'title' => Lang::t('title', 'Mobile connect'),
			'theme' => Ui::themeClass(),
			'user' => getUserFullname(CWebUser::$data),
			'server_url' => $server['url'],
			'server_name' => $server['name'],
			// A token is useless to the app when the role may not use the API.
			'api_access' => CWebUser::checkAccess('api.access'),
			'devices' => $devices,
			'csrf' => CCsrfTokenHelper::get('zbxview.connect.create')
		]));
	}
}
