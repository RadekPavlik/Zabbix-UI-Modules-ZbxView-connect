<?php declare(strict_types = 0);

namespace Modules\ZbxViewConnect\Actions;

use API;
use CController;
use CControllerResponseData;
use CRoleHelper;
use CWebUser;
use Modules\ZbxViewConnect\Includes\Lang;
use Modules\ZbxViewConnect\Includes\Pairing;

/**
 * Pairs the app: creates a never-expiring API token for the signed-in user
 * and returns the zbxview://add link the QR code carries.
 *
 * - repair=0 (first pairing): refused when the user is already paired, so a
 *   reload or a second tab never creates tokens behind the user's back.
 * - repair=1 ("Pair again"): the new token is created first, then the user's
 *   older ZbxView tokens are deleted - the previously paired phone loses
 *   access, the new one has it. Nothing is deleted if creation fails.
 */
class ConnectCreate extends CController {

	protected function init(): void {
		// The page posts JSON, with the CSRF token in the body.
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput(['repair' => 'in 0,1']);

		if (!$ret) {
			$this->respond(['error' => Lang::t('err_create', 'The API token could not be created.')]);
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest() && $this->checkAccess(CRoleHelper::ACTIONS_MANAGE_API_TOKENS);
	}

	protected function doAction(): void {
		$userid = (string) CWebUser::$data['userid'];
		$repair = (int) $this->getInput('repair', 0) === 1;

		if (!$repair && Pairing::active($userid) !== null) {
			$this->respond(['paired' => true]);

			return;
		}

		$client_cert = Pairing::clientCert();

		if (isset($client_cert['error'])) {
			// Configured but unusable: say so before any token is made.
			$this->respond(['error' => Lang::t('err_cert_'.$client_cert['error'],
				'The client certificate in the module configuration cannot be used.')]);

			return;
		}

		$old = array_column(Pairing::tokens($userid), 'tokenid');

		$now = time();
		$token_name = Pairing::TOKEN_PREFIX.date('Y-m-d H:i:s', $now);

		$result = API::Token()->create([
			'name' => $token_name,
			'description' => Lang::t('token_description', 'Created by ZbxView connect for the mobile app.'),
			'userid' => $userid,
			'expires_at' => 0,
			'status' => ZBX_AUTH_TOKEN_ENABLED
		]);

		if (!$result) {
			$messages = array_column(get_and_clear_messages(), 'message');
			$this->respond(['error' => $messages ? implode(' ', $messages)
				: Lang::t('err_create', 'The API token could not be created.')]);

			return;
		}

		[['token' => $token]] = API::Token()->generate($result['tokenids']);

		// Only now that the new token exists: one phone, one token.
		if ($old) {
			API::Token()->delete($old);
		}

		$server = Pairing::server();

		$link = 'zbxview://add?'.http_build_query([
			'url' => $server['url'],
			'name' => $server['name'],
			'auth' => 'token',
			'token' => $token,
			'self_signed' => $server['self_signed'] ? '1' : '0'
		] + ($server['pin'] !== '' ? ['pin' => $server['pin']] : [])
		+ ($client_cert ? array_filter([
			'cc' => $client_cert['cc'],
			'ck' => $client_cert['ck'],
			'ch' => $client_cert['ch']
		], 'strlen') : []), '', '&', PHP_QUERY_RFC3986);

		$this->respond([
			'link' => $link,
			// Several codes shown in turn when the link is too big for one.
			'parts' => Pairing::split($link),
			'token_name' => $token_name,
			'replaced' => count($old),
			'tail' => substr($token, -4)
		]);
	}

	private function respond(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
