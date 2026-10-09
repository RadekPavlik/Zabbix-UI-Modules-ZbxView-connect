<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Actions;

use APP;
use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ZbxViewConnect\Includes\Lang;
use Modules\ZbxViewConnect\Includes\Pairing;

/**
 * The admin page's actions (JSON POST from settings.js):
 *
 * - action=check: checks an uploaded certificate (base64 of the file,
 *   password, hosts) without storing anything; answers its description and
 *   the preview data.
 * - action=save: stores everything at once - url, name, server_cert (0/1),
 *   cert_required (0/1) and, when a new file was chosen, the certificate
 *   (cert, cert_name, password) plus its hosts. A certificate is stored only
 *   when usable (EC key, key matches, password right); cert_required=0
 *   forgets it. Other config keys are kept.
 */
class SettingsUpdate extends CController {

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'action' => 'required|in check,save',
			'url' => 'string',
			'name' => 'string',
			'server_cert' => 'in 0,1',
			'cert_required' => 'in 0,1',
			'cert' => 'string',
			'cert_name' => 'string',
			'password' => 'string',
			'hosts' => 'string',
			'push_api_key' => 'string',
			'push_app_id' => 'string',
			'push_sender_id' => 'string',
			'push_project_id' => 'string'
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
		$hosts = self::hosts((string) $this->getInput('hosts', ''));
		$upload = $this->upload();

		if (isset($upload['error'])) {
			$this->respond($upload);

			return;
		}

		if ($this->getInput('action') === 'check') {
			if ($upload === null) {
				$this->respond(['error' => Lang::t('s_cc_no_file', 'Choose the certificate file first.')]);

				return;
			}

			$this->respondCert($upload['check'], $upload['name'], (string) ($config['url'] ?? ''));

			return;
		}

		// --- save ---------------------------------------------------------
		$url = rtrim(trim((string) $this->getInput('url', '')), '/');

		if ($url !== '') {
			$parts = parse_url($url);

			if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
					|| ($parts['host'] ?? '') === '') {
				$this->respond(['error' => Lang::t('err_url', 'Enter the address as https://host/path.')]);

				return;
			}
		}

		// The Firebase project for push notifications: all four or none.
		$push = [];

		foreach (array_keys(Pairing::PUSH_KEYS) as $key) {
			$push[$key] = trim((string) $this->getInput($key, ''));
		}

		$push_error = Pairing::pushError($push);

		if ($push_error !== null) {
			$this->respond(['error' => $push_error === 'incomplete'
				? Lang::t('err_push_incomplete', 'Fill in all four Firebase values or none.')
				: Lang::t('err_push_format',
					'The Firebase values do not look right (app ID 1:<number>:android:<hex>, sender ID digits only).')]);

			return;
		}

		$config['url'] = $url;
		$config['name'] = trim((string) $this->getInput('name', ''));
		$config['self_signed'] = (string) $this->getInput('server_cert', '0');
		$config = $push + $config;

		if ((string) $this->getInput('cert_required', '0') === '0') {
			$config['client_cert'] = $config['client_cert_password'] = $config['client_cert_hosts'] = '';
			$config['client_cert_name'] = '';
		}
		elseif ($upload !== null) {
			$config['client_cert'] = $upload['value'];
			$config['client_cert_password'] = $upload['password'];
			$config['client_cert_name'] = $upload['name'];
			$config['client_cert_hosts'] = $hosts;
		}
		else {
			// No new file: the stored one must exist and still be usable.
			$check = Pairing::clientCertFrom((string) ($config['client_cert'] ?? ''),
				(string) ($config['client_cert_password'] ?? ''), $hosts);

			if ($check === [] || isset($check['error'])) {
				$this->respond(['error' => $check === []
					? Lang::t('s_cc_no_file', 'Choose the certificate file first.')
					: Lang::t('err_cert_'.$check['error'], 'The client certificate cannot be used.')]);

				return;
			}

			$config['client_cert_hosts'] = $hosts;
		}

		$module->setConfig($config);

		$messages = array_column(get_and_clear_messages(), 'message');

		if ($messages) {
			$this->respond(['error' => implode(' ', $messages)]);

			return;
		}

		$this->respondCert(Pairing::clientCertFrom((string) ($config['client_cert'] ?? ''),
			(string) ($config['client_cert_password'] ?? ''), (string) ($config['client_cert_hosts'] ?? '')),
			(string) ($config['client_cert_name'] ?? ''), $url);
	}

	/**
	 * The uploaded certificate, checked: null when no file was sent,
	 * ['error' => ...] when unusable.
	 */
	private function upload(): ?array {
		$raw = trim((string) $this->getInput('cert', ''));

		if ($raw === '') {
			return null;
		}

		$bin = base64_decode($raw, true);

		if ($bin === false) {
			return ['error' => Lang::t('err_cert_invalid', 'The client certificate cannot be read.')];
		}

		// PEM stays text (readable in the config); PKCS#12 stays base64.
		$value = strpos($bin, '-----BEGIN') !== false ? $bin : $raw;
		$password = (string) $this->getInput('password', '');
		$check = Pairing::clientCertFrom($value, $password, self::hosts((string) $this->getInput('hosts', '')));

		if ($check === [] || isset($check['error'])) {
			return ['error' => Lang::t('err_cert_'.($check['error'] ?? 'invalid'), 'The client certificate cannot be used.')];
		}

		$name = basename(str_replace('\\', '/', trim((string) $this->getInput('cert_name', ''))));

		return ['value' => $value, 'password' => $password, 'name' => $name, 'check' => $check];
	}

	private static function hosts(string $raw): string {
		return implode(',', array_values(array_unique(array_filter(array_map(
			static fn($h) => strtolower(trim((string) $h)), preg_split('/[,;\s]+/', $raw)
		), static fn($h) => $h !== ''))));
	}

	private function respondCert(array $check, string $file, string $url): void {
		$this->respond([
			'ok' => true,
			'cert' => Pairing::describe($check, $url) + ['file' => $file],
			'preview' => SettingsView::preview($check)
		]);
	}

	private function respond(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
