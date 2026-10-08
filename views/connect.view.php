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

$value = static fn(string $label, string $text, bool $mono = false) => (new CDiv([
	(new CTag('label', true, $label))->addClass('zvc-label'),
	(new CDiv($text))->addClass('zvc-value')->addClass($mono ? 'zvc-mono' : null)
]))->addClass('zvc-field');

// --- left: this server + the pairing state --------------------------------
$state = $data['paired']
	? [
		(new CDiv([
			(new CSpan(Lang::t('paired_since', 'Paired')))->addClass('zvc-k'),
			(new CSpan($data['created']))->addClass('zvc-v'),
			(new CSpan(Lang::t('last_used', 'Last used by the app')))->addClass('zvc-k'),
			(new CSpan($data['lastaccess']))->addClass('zvc-v')
		]))->addClass('zvc-facts'),
		(new CDiv([
			(new CButton('zvc-repair', [Ui::icon('refresh'), Lang::t('repair', 'Pair again')]))
				->setId('zvc-repair')->addClass('zvc-btn'),
			(new CSpan(Lang::t('repair_note',
				'A new code replaces the token - the phone connected now stops working.')))->addClass('zvc-note')
		]))->addClass('zvc-actions')->addStyle('margin-top: 18px;')
	]
	: (new CDiv(Lang::t('first_note', 'The code is created as soon as you open this page.')))
		->addClass('zvc-note')->addStyle('margin-top: 4px;');

$left = (new CDiv([
	$head(Lang::t('form_title', 'Add this server to the app'),
		Lang::t('form_intro', 'Scan the code in ZbxView - the server and your sign-in are added at once.')),
	(new CDiv([
		$value(Lang::t('name', 'Name in the app'), $data['server_name']),
		$value(Lang::t('url', 'Server address'), $data['server_url'], true),
		$state
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
			(new CDiv([new CTag('small', true, Lang::t('server', 'Server')), new CTag('b', true, $data['server_name'])])),
			(new CDiv([new CTag('small', true, Lang::t('token', 'Token')), (new CTag('b', true, ''))->setId('zvc-token-name')->addClass('zvc-mono')])),
			(new CDiv([new CTag('small', true, Lang::t('expires', 'Expires')), new CTag('b', true, Lang::t('never', 'Never'))]))
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
	(new CDiv([Ui::icon('qr'), new CDiv(Lang::t('empty_code', 'The code appears here when you pair'))]))->addClass('zvc-empty'),
	(new CDiv([
		new CDiv([(new CDiv('1'))->addClass('zvc-step'), Lang::t('step1', 'Open this page or tap Pair again')]),
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
			->setAttribute('data-paired', $data['paired'] ? '1' : '0')
			->setAttribute('data-api', $data['api_access'] ? '1' : '0')
			->setAttribute('data-confirm', Lang::t('repair_confirm',
				'Pair again? The phone connected now will stop working.'))
			->setAttribute('data-wait', Lang::t('wait', 'Creating the code…'))
			->setAttribute('data-copied', Lang::t('copied', 'Copied'))
			->setAttribute('data-hides', Lang::t('hides_in', 'Hides in'))
			->setAttribute('data-part-label', Lang::t('part_label',
				'Code {i} of {n} - keep the phone on it until the app has read all of them'))
	)
	->show();
