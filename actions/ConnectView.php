<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Actions;

use CController;
use CControllerResponseData;
use CCsrfTokenHelper;
use CRoleHelper;
use CWebUser;
use Modules\ZbxViewConnect\Includes\Lang;
use Modules\ZbxViewConnect\Includes\Pairing;

/**
 * The "Connect application" page. Not paired yet: connect.js asks
 * ConnectCreate for a code right away. Paired: the page shows since when and
 * offers "Pair again", which replaces the token.
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
		$active = Pairing::active((string) CWebUser::$data['userid']);

		$this->setResponse(new CControllerResponseData([
			'title' => Lang::t('title', 'Connect application'),
			// A token is useless to the app when the role may not use the API.
			'api_access' => CWebUser::checkAccess('api.access'),
			'paired' => $active !== null,
			'created' => $active !== null ? zbx_date2str(DATE_TIME_FORMAT, (int) $active['created_at']) : '',
			'lastaccess' => $active !== null && (int) $active['lastaccess'] > 0
				? zbx_date2str(DATE_TIME_FORMAT, (int) $active['lastaccess'])
				: Lang::t('never_used', 'not yet'),
			'csrf' => CCsrfTokenHelper::get('zbxview.connect.create')
		]));
	}
}
