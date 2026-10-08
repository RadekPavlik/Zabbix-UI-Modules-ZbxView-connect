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
 * Pairs devices: every phone has its own never-expiring API token
 * ("ZbxView · <device>"); the zbxview://add link of a new token is returned
 * for the QR code.
 *
 * mode=first   the first device, made when the page opens - refused when the
 *              user already has one, so a reload or a second tab never adds
 *              tokens behind the user's back.
 * mode=add     another device, named by the user (`device`).
 * mode=repair  a new code for one device (`tokenid`): its new token is made
 *              first, then the old one deleted and the new one takes the
 *              device's name - that phone has to scan again, the others are
 *              untouched. Nothing is deleted if creation fails.
 * mode=remove  forgets one device (`tokenid`): its token is deleted.
 */
class ConnectCreate extends CController {

	protected function init(): void {
		// The page posts JSON, with the CSRF token in the body.
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'mode' => 'required|in first,add,repair,remove',
			'device' => 'string',
			'tokenid' => 'id'
		]);

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
		$mode = (string) $this->getInput('mode');
		$devices = Pairing::devices($userid, Lang::t('device_default', 'Phone'));
		$device = null;

		if ($mode === 'repair' || $mode === 'remove') {
			// Only the user's own ZbxView tokens.
			foreach ($devices as $d) {
				if ($d['tokenid'] === (string) $this->getInput('tokenid', '')) {
					$device = $d;
				}
			}

			if ($device === null) {
				$this->respond(['error' => Lang::t('err_device', 'This device is no longer paired.')]);

				return;
			}
		}

		if ($mode === 'remove') {
			API::Token()->delete([$device['tokenid']]);
			$this->respond(['removed' => true]);

			return;
		}

		if ($mode === 'first' && $devices) {
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

		$device_name = $mode === 'repair'
			? $device['name']
			: trim((string) $this->getInput('device', ''));

		if ($device_name === '') {
			$device_name = Lang::t('device_default', 'Phone');
		}

		// A repaired device gets a temporary name until its old token is gone.
		$token_name = Pairing::tokenName($userid, $mode === 'repair' ? $device_name.' ~' : $device_name);

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

		$tokenid = $result['tokenids'][0];
		[['token' => $token]] = API::Token()->generate([$tokenid]);

		if ($mode === 'repair') {
			// Only now that the new token exists: the old one of THIS device goes.
			API::Token()->delete([$device['tokenid']]);
			$token_name = Pairing::tokenName($userid, $device_name);
			API::Token()->update(['tokenid' => $tokenid, 'name' => $token_name]);
		}

		$link = Pairing::link(Pairing::server(), $token, $client_cert);

		$this->respond([
			'link' => $link,
			// Several codes shown in turn when the link is too big for one.
			'parts' => Pairing::split($link),
			'token_name' => $token_name,
			'device' => $device_name,
			'tail' => substr($token, -4)
		]);
	}

	private function respond(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
