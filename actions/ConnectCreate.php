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
 * Pairs devices: every phone has its own API token ("ZbxView · <device>")
 * that never expires once the phone has used it; the zbxview://add link of a
 * new token is returned for the QR code. A code nobody scans within
 * Pairing::PAIR_WINDOW is dropped again (Pairing::settle(), run here and when
 * the page opens), so a device only ever appears once a phone really paired.
 *
 * mode=first    the first device, made when the page opens - refused when the
 *               user already has one, so a reload or a second tab never adds
 *               tokens behind the user's back.
 * mode=add      another device, named by the user (`device`).
 * mode=repair   a new code for one device (`tokenid`): the new token waits as
 *               "<device> ~" and the old one keeps working until the phone
 *               scans the new code; only then settle() swaps them. A code
 *               nobody scans is dropped and the device is as it was.
 * mode=status   has the phone used the new token (`tokenid`) yet?
 *               {paired: true|false} or {gone: true} once it was dropped.
 * mode=discard  drops a code (`tokenid`) nobody has used yet (the page hid
 *               it); a token a phone already uses is left alone.
 * mode=remove   forgets one device (`tokenid`): its token is deleted.
 */
class ConnectCreate extends CController {

	protected function init(): void {
		// The page posts JSON, with the CSRF token in the body.
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'mode' => 'required|in first,add,repair,remove,status,discard',
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
		$legacy = Lang::t('device_default', 'Phone');
		$tokenid = (string) $this->getInput('tokenid', '');

		// Whatever the phones did meanwhile: finished repairs, stale codes.
		$settled = Pairing::settle($userid, $legacy);

		if ($mode === 'status') {
			$token = Pairing::token($userid, $tokenid);

			if ($token === null) {
				$this->respond(['gone' => true]);
			}
			else {
				$this->respond(['paired' => !Pairing::isPending($token),
					'device' => $settled['paired'][$tokenid] ?? null]);
			}

			return;
		}

		if ($mode === 'discard') {
			$token = Pairing::token($userid, $tokenid);

			if ($token !== null && Pairing::isPending($token)) {
				API::Token()->delete([$token['tokenid']]);
				$this->respond(['removed' => true]);
			}
			else {
				// Already used by a phone (or gone): nothing to drop.
				$this->respond(['removed' => false, 'paired' => $token !== null]);
			}

			return;
		}

		$devices = Pairing::devices($userid, $legacy);
		$device = null;

		if ($mode === 'repair' || $mode === 'remove') {
			// Only the user's own, paired devices.
			foreach ($devices as $d) {
				if ($d['tokenid'] === $tokenid) {
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
			// The repair mark at the end of a name is ours, not the user's.
			: rtrim(trim((string) $this->getInput('device', '')), '~ ');

		if ($device_name === '') {
			$device_name = $legacy;
		}

		// A repaired device's new token waits under a marked name until the
		// phone has used it (then settle() swaps it in).
		$token_name = $mode === 'repair'
			? Pairing::tokenName($userid, $device_name, Pairing::REPAIR_MARK)
			: Pairing::tokenName($userid, $device_name);

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

		$new_tokenid = (string) $result['tokenids'][0];
		[['token' => $token]] = API::Token()->generate([$new_tokenid]);

		$link = Pairing::link(Pairing::server(), $token, $client_cert);

		$this->respond([
			'link' => $link,
			// Several codes shown in turn when the link is too big for one.
			'parts' => Pairing::split($link),
			'tokenid' => $new_tokenid,
			'token_name' => $token_name,
			'device' => $device_name,
			'tail' => substr($token, -4)
		]);
	}

	private function respond(array $data): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($data)]));
	}
}
