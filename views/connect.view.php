<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

use Modules\ZbxViewConnect\Includes\Lang;
use Modules\ZbxViewConnect\Includes\Ui;

$head = static fn(string $title, string $sub = '', $right = null) => (new CDiv([
	(new CDiv([
		(new CTag('h2', true, $title))->addClass('zvc-h'),
		$sub !== '' ? (new CDiv($sub))->addClass('zvc-sub') : null
	]))->addClass('zvc-grow'),
	$right
]))->addClass('zvc-card-head');

// --- left: the user's devices ----------------------------------------------
$rows = [];

foreach ($data['devices'] as $d) {
	$rows[] = (new CDiv([
		(new CDiv(Ui::icon('phone')))->addClass('zvc-dev-ico'),
		(new CDiv([
			(new CDiv($d['name']))->addClass('zvc-dev-name'),
			(new CDiv(Lang::t('paired_since', 'Paired').' '.$d['created_text']))->addClass('zvc-dev-meta'),
			(new CDiv(Lang::t('last_used_short', 'Last used').' '.$d['lastaccess_text']))->addClass('zvc-dev-meta')
		]))->addClass('zvc-grow'),
		(new CSimpleButton([Ui::icon('refresh'), Lang::t('new_code', 'New QR code')]))
			->addClass('zvc-btn')->addClass('zvc-btn-alt')->addClass('zvc-btn-sm')->addClass('js-repair')
			->setAttribute('data-tokenid', $d['tokenid'])->setAttribute('data-name', $d['name']),
		(new CSimpleButton(Ui::icon('trash')))
			->addClass('zvc-btn')->addClass('zvc-btn-alt')->addClass('zvc-btn-sm')->addClass('zvc-btn-icon')
			->addClass('js-remove')->setAttribute('title', Lang::t('remove_device', 'Remove device'))
			->setAttribute('data-tokenid', $d['tokenid'])->setAttribute('data-name', $d['name'])
	]))->addClass('zvc-dev')->setAttribute('data-tokenid', $d['tokenid']);
}

$left = (new CDiv([
	$head(Lang::t('devices_title', 'Your devices'),
		Lang::t('devices_intro', 'Each phone has its own sign-in. A new code or removing one device does not affect the others.')),
	(new CDiv([
		(new CDiv([
			Lang::t('server', 'Server').': ', new CTag('b', true, $data['server_name']), ' · ',
			(new CSpan($data['server_url']))->addClass('zvc-mono')
		]))->addClass('zvc-server-line'),
		$rows
			? (new CDiv($rows))->addClass('zvc-devices')
			: (new CDiv(Lang::t('first_note', 'The code is created as soon as you open this page.')))->addClass('zvc-none'),
		(new CDiv([
			(new CTag('input', false))->setAttribute('type', 'text')->setId('zvc-device-name')->addClass('zvc-input')
				->setAttribute('maxlength', 48)
				->setAttribute('placeholder', Lang::t('device_placeholder', 'Device name, e.g. Work phone')),
			(new CSimpleButton([Ui::icon('plus'), Lang::t('add_device', 'Add another device')]))
				->setId('zvc-add')->addClass('zvc-btn')
		]))->addClass('zvc-add')
	]))->addClass('zvc-card-body')
]))->addClass('zvc-card');

// --- right: the code, or what to do ---------------------------------------
$result = (new CDiv([
	(new CDiv([
		(new CDiv([
			(new CDiv())->setId('zvc-qr')->addClass('zvc-qr'),
			(new CDiv(new CTag('i', true)))->setId('zvc-bar')->addClass('zvc-bar'),
			(new CDiv())->setId('zvc-part')->addClass('zvc-part')
		]))->addClass('zvc-qr-col'),
		(new CDiv([
			(new CDiv([new CTag('small', true, Lang::t('device', 'Device')), (new CTag('b', true, ''))->setId('zvc-device')])),
			(new CDiv([new CTag('small', true, Lang::t('server', 'Server')), new CTag('b', true, $data['server_name'])])),
			(new CDiv([new CTag('small', true, Lang::t('token', 'Token')), (new CTag('b', true, ''))->setId('zvc-token-name')->addClass('zvc-mono')])),
			(new CDiv([new CTag('small', true, Lang::t('expires', 'Expires')), new CTag('b', true, Lang::t('never_once_used', 'Never, once the phone has used it'))]))
		]))->addClass('zvc-stack')
	]))->addClass('zvc-qr-row'),
	(new CDiv([Ui::icon('warn'), new CSpan(Lang::t('secret_warn',
		'The code signs in as you. Do not share it or leave it on screen - it is shown only now. You can disable the token under API tokens.'))]))
		->addClass('zvc-warn'),
	(new CDiv([
		(new CLink(Lang::t('open_on_phone', 'Open in the app')))->setId('zvc-open')->addClass('zvc-btn'),
		(new CSimpleButton(Lang::t('copy', 'Copy link')))->setId('zvc-copy')->addClass('zvc-btn')->addClass('zvc-btn-alt'),
		(new CSimpleButton([Ui::icon('eye_off'), Lang::t('hide', 'Hide now')]))->setId('zvc-done')
			->addClass('zvc-btn')->addClass('zvc-btn-alt')
	]))->addClass('zvc-actions')
]))->setId('zvc-result')->addClass('zvc-hidden');

$empty = (new CDiv([
	(new CDiv([Ui::icon('qr'), new CDiv(Lang::t('empty_code', 'The code appears here when you pair a device'))]))->addClass('zvc-empty'),
	(new CDiv([
		new CDiv([(new CDiv('1'))->addClass('zvc-step'), Lang::t('step1', 'Add a device or tap New QR code')]),
		new CDiv([(new CDiv('2'))->addClass('zvc-step'), Lang::t('step2', 'In ZbxView tap Add server')]),
		new CDiv([(new CDiv('3'))->addClass('zvc-step'), Lang::t('step3', 'Tap Scan QR code and point the phone here')])
	]))->addClass('zvc-steps')
]))->setId('zvc-empty');

$right = (new CDiv([
	$head(Lang::t('result_title', 'Scan in the app'), '',
		(new CSpan([Ui::icon('timer'), (new CSpan(''))->setId('zvc-timer-text')]))->setId('zvc-timer')
			->addClass('zvc-timer')->addClass('zvc-hidden')
	),
	(new CDiv([
		(new CDiv())->setId('zvc-error')->addClass('zvc-msg')->addStyle('margin-bottom: 14px;'),
		$data['api_access'] ? null : (new CDiv([Ui::icon('warn'), new CSpan(Lang::t('no_api',
			'Your user role does not allow API access, so the app could not sign in. Ask your Zabbix administrator.'))]))
			->addClass('zvc-warn'),
		$result,
		$empty
	]))->addClass('zvc-card-body')
]))->addClass('zvc-card');

(new CHtmlPage())
	->setTitle($data['title'])
	->setControls((new CDiv(Lang::t('signed_in_as', 'Signed in as').' '.$data['user']))->addClass('zvc-note'))
	->addItem(
		(new CDiv((new CDiv([$left, $right]))->addClass('zvc-grid')->addClass('zvc-even')))
			->addClass('zvc')
			->addClass($data['theme'])
			->setAttribute('data-csrf', $data['csrf'])
			->setAttribute('data-paired', $data['devices'] ? '1' : '0')
			->setAttribute('data-window', (string) $data['window'])
			->setAttribute('data-api', $data['api_access'] ? '1' : '0')
			->setAttribute('data-t', json_encode([
				'repair' => Lang::t('repair_confirm_device',
					'New code for "{name}"? That phone keeps working until it scans the new code; other devices are not affected.'),
				'remove' => Lang::t('remove_confirm',
					'Remove "{name}"? That phone loses access; other devices are not affected.'),
				'wait' => Lang::t('wait', 'Creating the code…'),
				'copied' => Lang::t('copied', 'Copied'),
				'hides' => Lang::t('hides_in', 'Hides in'),
				'paired_ok' => Lang::t('paired_ok', 'Phone connected - the device is now listed.'),
				'timed_out' => Lang::t('timed_out', 'Nobody scanned the code in time, so it was discarded. Add a device or tap New QR code for a new one.'),
				'part' => Lang::t('part_label', 'Code {i} of {n} - keep the phone on it until the app has read all of them')
			]))
	)
	->show();
