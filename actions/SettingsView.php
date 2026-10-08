<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Actions;

use APP;
use CController;
use CControllerResponseData;
use CCsrfTokenHelper;
use CWebUser;
use Modules\ZbxViewConnect\Includes\Lang;
use Modules\ZbxViewConnect\Includes\Pairing;

/**
 * Administration → General → ZbxView connect: what the QR code carries
 * (address, name, server certificate handling) and the shared client
 * certificate, imported and checked right here instead of on the server's
 * file system. Super admins only - the config is module-wide.
 */
class SettingsView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$config = Pairing::config();
		$cert = Pairing::describe(Pairing::clientCert(), (string) ($config['url'] ?? ''));

		$this->setResponse(new CControllerResponseData([
			'title' => Lang::t('settings_title', 'ZbxView connect'),
			'url' => (string) ($config['url'] ?? ''),
			'url_auto' => Pairing::frontendUrl(),
			'name' => (string) ($config['name'] ?? ''),
			'self_signed' => (string) ($config['self_signed'] ?? 'auto'),
			'cert' => $cert,
			'cert_hosts' => (string) ($config['client_cert_hosts'] ?? ''),
			'csrf' => CCsrfTokenHelper::get('zbxview.connect.settings.update'),
			'module_ok' => APP::ModuleManager()->getModule('zbxviewconnect') !== null
		]));
	}
}
