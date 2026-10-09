<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Actions;

use CController;
use CControllerResponseData;
use CCsrfTokenHelper;
use CWebUser;
use Modules\ZbxViewConnect\Includes\Lang;
use Modules\ZbxViewConnect\Includes\Pairing;
use Modules\ZbxViewConnect\Includes\Ui;

/**
 * Administration → Mobile configuration (ZbxView connect): what the QR code carries
 * (address, name, server certificate handling) and the shared client
 * certificate, imported and checked right here. A preview shows the codes
 * users will scan - with a placeholder token and key, never the real ones.
 * Super admins only - the config is module-wide.
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
		$cert = Pairing::clientCert();

		$this->setResponse(new CControllerResponseData([
			'title' => Lang::t('settings_title', 'Mobile configuration'),
			'theme' => Ui::themeClass(),
			'url' => (string) ($config['url'] ?? ''),
			'url_auto' => Pairing::frontendUrl(),
			'name' => (string) ($config['name'] ?? ''),
			'name_auto' => Pairing::server(false)['name'],
			// "auto" (pin only an untrusted certificate) shows as Public CA:
			// the two options of the page are the two real cases.
			'server_cert' => (string) ($config['self_signed'] ?? 'auto') === '1' ? '1' : '0',
			'cert' => Pairing::describe($cert, (string) ($config['url'] ?? '')),
			'cert_file' => (string) ($config['client_cert_name'] ?? ''),
			'cert_hosts' => (string) ($config['client_cert_hosts'] ?? ''),
			'preview' => self::preview($cert),
			// The Firebase project (push notifications), field by field.
			'push' => [
				'api_key' => (string) ($config['push_api_key'] ?? ''),
				'app_id' => (string) ($config['push_app_id'] ?? ''),
				'sender_id' => (string) ($config['push_sender_id'] ?? ''),
				'project_id' => (string) ($config['push_project_id'] ?? '')
			],
			'csrf' => CCsrfTokenHelper::get('zbxview.connect.settings.update')
		]));
	}

	/**
	 * What settings.js needs to draw the preview: the certificate part of the
	 * link with the KEY REPLACED by a placeholder of the same length, so the
	 * codes have their real size but the page never carries the key.
	 */
	public static function preview(array $cert): array {
		if ($cert === [] || isset($cert['error'])) {
			return ['cc' => '', 'ck_len' => 0, 'ch' => ''];
		}

		return ['cc' => $cert['cc'], 'ck_len' => strlen($cert['ck']), 'ch' => $cert['ch']];
	}
}
