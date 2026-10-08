<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Actions;

use APP;
use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ZbxViewConnect\Includes\Lang;
use Modules\ZbxViewConnect\Includes\Pairing;

/**
 * Saves the ZbxView connect configuration (JSON POST from settings.js).
 *
 * - settings: url, name, self_signed - validated, then stored.
 * - certificate: cert (base64 of the uploaded file: PKCS#12 or PEM),
 *   password, hosts. Checked with the same code the pairing page uses
 *   (EC key, key matches certificate, password right) and stored only when
 *   usable; the response describes it (CN, validity, hosts).
 * - remove_cert=1: forgets the certificate.
 * Other config keys are kept.
 */
class SettingsUpdate extends CController {

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'section' => 'required|in settings,certificate,remove_cert',
			'url' => 'string',
			'name' => 'string',
			'self_signed' => 'in auto,0,1',
			'cert' => 'string',
			'password' => 'string',
			'hosts' => 'string'
		]);

		if (!$ret) {
			$this->respond(['error' => Lang::t('err_input', 'Invalid input.')]);
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$module = APP::ModuleManager()->getModule('zbxviewconnect');

		if ($module === null) {
			$this->respond(['error' => Lang::t('err_module', 'The module is not enabled.')]);

			return;
		}

		$config = $module->getConfig();

		switch ($this->getInput('section')) {
			case 'settings':
				$url = rtrim(trim((string) $this->getInput('url', '')), '/');

				if ($url !== '') {
					$parts = parse_url($url);

					if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
							|| ($parts['host'] ?? '') === '') {
						$this->respond(['error' => Lang::t('err_url', 'Enter the address as https://host/path.')]);

						return;
					}
				}

				$config['url'] = $url;
				$config['name'] = trim((string) $this->getInput('name', ''));
				$config['self_signed'] = (string) $this->getInput('self_signed', 'auto');
				break;

			case 'certificate':
				$raw = trim((string) $this->getInput('cert', ''));
				$bin = base64_decode($raw, true);

				if ($raw === '' || $bin === false) {
					$this->respond(['error' => Lang::t('err_cert_invalid',
						'The client certificate cannot be read.')]);

					return;
				}

				// PEM stays text (readable in the config); PKCS#12 stays base64.
				$value = strpos($bin, '-----BEGIN') !== false ? $bin : $raw;
				$password = (string) $this->getInput('password', '');
				$hosts = trim((string) $this->getInput('hosts', ''));
				$check = Pairing::clientCertFrom($value, $password, $hosts);

				if ($check === [] || isset($check['error'])) {
					$code = $check['error'] ?? 'invalid';
					$this->respond(['error' => Lang::t('err_cert_'.$code,
						'The client certificate cannot be used.')]);

					return;
				}

				$config['client_cert'] = $value;
				$config['client_cert_password'] = $password;
				$config['client_cert_hosts'] = $hosts;
				break;

			case 'remove_cert':
				$config['client_cert'] = '';
				$config['client_cert_password'] = '';
				$config['client_cert_hosts'] = '';
				break;
		}

		$module->setConfig($config);

		$messages = array_column(get_and_clear_messages(), 'message');

		if ($messages) {
			$this->respond(['error' => implode(' ', $messages)]);

			return;
		}

		$this->respond(['ok' => true, 'cert' => Pairing::describe(Pairing::clientCertFrom(
			(string) ($config['client_cert'] ?? ''),
			(string) ($config['client_cert_password'] ?? ''),
			(string) ($config['client_cert_hosts'] ?? '')
		), $config['url'] ?? '')]);
	}

	private function respond(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
