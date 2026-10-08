<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Actions;

use API;
use CController;
use CControllerResponseData;
use CProfile;
use CRoleHelper;
use CWebUser;
use Modules\ZbxViewConnect\Includes\Lang;

/**
 * Creates an API token for the signed-in user and returns the zbxview://add
 * link the QR code carries. The token is the user's own (listed under User
 * settings - API tokens, where it can be disabled or deleted) and is
 * returned only this once.
 */
class ConnectCreate extends CController {

	private const VALIDITY_DAYS = [30, 90, 365, 0];

	protected function init(): void {
		// The page posts JSON, with the CSRF token in the body.
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'name' => 'required|string|not_empty',
			'url' => 'required|string|not_empty',
			'days' => 'required|in '.implode(',', self::VALIDITY_DAYS),
			'self_signed' => 'in 0,1'
		]);

		if ($ret) {
			$url = parse_url(trim((string) $this->getInput('url')));

			if (!is_array($url) || !in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
					|| ($url['host'] ?? '') === '') {
				$ret = false;
			}
		}

		if (!$ret) {
			$this->respond(['error' => Lang::t('err_url', 'Enter the address as https://host/path.')]);
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest() && $this->checkAccess(CRoleHelper::ACTIONS_MANAGE_API_TOKENS);
	}

	protected function doAction(): void {
		$name = trim((string) $this->getInput('name'));
		$url = rtrim(trim((string) $this->getInput('url')), '/');
		$days = (int) $this->getInput('days');
		$self_signed = (int) $this->getInput('self_signed', 0) === 1;

		// Remembered per user, so a second phone starts from the same answers.
		CProfile::update(ConnectView::PROFILE_URL, $url, PROFILE_TYPE_STR);
		CProfile::update(ConnectView::PROFILE_NAME, $name, PROFILE_TYPE_STR);

		$now = time();
		$token_name = 'ZbxView '.date('Y-m-d H:i:s', $now);
		$expires = $days > 0 ? $now + $days * 86400 : 0;

		$result = API::Token()->create([
			// Token names are unique per user; the timestamp keeps them apart
			// and tells which phone was connected when.
			'name' => $token_name,
			'description' => Lang::t('token_description', 'Created by ZbxView connect for the mobile app.'),
			'userid' => CWebUser::$data['userid'],
			'expires_at' => $expires,
			'status' => ZBX_AUTH_TOKEN_ENABLED
		]);

		if (!$result) {
			$messages = array_column(get_and_clear_messages(), 'message');
			$this->respond(['error' => $messages ? implode(' ', $messages)
				: Lang::t('err_create', 'The API token could not be created.')]);

			return;
		}

		[['token' => $token]] = API::Token()->generate($result['tokenids']);

		$link = 'zbxview://add?'.http_build_query([
			'url' => $url,
			'name' => $name,
			'auth' => 'token',
			'token' => $token,
			'self_signed' => $self_signed ? '1' : '0'
		], '', '&', PHP_QUERY_RFC3986);

		$this->respond([
			'link' => $link,
			'token_name' => $token_name,
			'expires' => $expires > 0 ? zbx_date2str(DATE_FORMAT, $expires) : Lang::t('never', 'Never'),
			'tail' => substr($token, -4)
		]);
	}

	private function respond(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
