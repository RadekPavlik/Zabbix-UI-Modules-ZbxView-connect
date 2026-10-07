<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

use Modules\ZbxViewConnect\Includes\Lang;

$field = static fn(string $label, $control, string $hint = '') => (new CDiv([
	(new CTag('label', true, $label))->addClass('zvc-label'),
	$control,
	$hint !== '' ? (new CDiv($hint))->addClass('zvc-hint') : null
]))->addClass('zvc-field');

$validity = (new CSelect('days'))
	->setId('zvc-days')
	->addOptions(CSelect::createOptionsFromArray([
		30 => Lang::t('days_30', '30 days'),
		90 => Lang::t('days_90', '90 days'),
		365 => Lang::t('days_365', '1 year'),
		0 => Lang::t('days_never', 'Never expires')
	]))
	->setValue(90);

$form = (new CDiv([
	(new CTag('h2', true, Lang::t('form_title', 'Add this server to the app')))->addClass('zvc-h'),
	(new CDiv(Lang::t('form_intro',
		'Creates an API token for you and shows it as a QR code. In the app tap Add server → Scan QR code.'
	)))->addClass('zvc-intro'),
	$data['api_access'] ? null : (new CDiv(Lang::t('no_api',
		'Your user role does not allow API access, so the app could not sign in with this token. Ask your Zabbix administrator.'
	)))->addClass('zvc-warn'),
	$field(Lang::t('name', 'Name in the app'),
		(new CTextBox('name', $data['name']))->setId('zvc-name')->setAttribute('maxlength', 64)
	),
	$field(Lang::t('url', 'Server address'),
		(new CTextBox('url', $data['url']))->setId('zvc-url')->setAttribute('spellcheck', 'false'),
		Lang::t('url_hint', 'The address the phone reaches this Zabbix at - from outside the office it may differ from the one in your browser.')
	),
	$field(Lang::t('validity', 'Token valid for'), $validity),
	(new CDiv([
		(new CCheckBox('self_signed'))->setId('zvc-self-signed')->setUncheckedValue(0),
		new CLabel(Lang::t('self_signed', 'Server uses a self-signed certificate'), 'zvc-self-signed')
	]))->addClass('zvc-check'),
	(new CDiv([
		(new CButton('zvc-generate', Lang::t('generate', 'Create QR code')))->setId('zvc-generate'),
		(new CSpan())->setId('zvc-error')->addClass('zvc-error')
	]))->addClass('zvc-actions')
]))->addClass('zvc-card zvc-form');

$result = (new CDiv([
	(new CDiv([
		(new CTag('h2', true, Lang::t('result_title', 'Scan in the app')))->addClass('zvc-h'),
		(new CDiv())->setId('zvc-qr')->addClass('zvc-qr'),
		(new CDiv([
			(new CSpan(Lang::t('token', 'Token')))->addClass('zvc-k'),
			(new CSpan())->setId('zvc-token-name'),
			(new CSpan(Lang::t('expires', 'Expires')))->addClass('zvc-k'),
			(new CSpan())->setId('zvc-expires')
		]))->addClass('zvc-facts'),
		(new CDiv(Lang::t('secret_warn',
			'The code signs in as you. Do not share it or leave it on screen; it is shown only now. The token can be disabled or deleted under API tokens.'
		)))->addClass('zvc-warn'),
		(new CDiv([
			(new CLink(Lang::t('open_on_phone', 'Open in the app')))->setId('zvc-open')->addClass('zvc-btn-link'),
			(new CSimpleButton(Lang::t('copy', 'Copy link')))->setId('zvc-copy')->addClass(ZBX_STYLE_BTN_ALT),
			(new CSimpleButton(Lang::t('hide', 'Hide')))->setId('zvc-hide')->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('zvc-actions'),
		(new CDiv(new CLink(Lang::t('manage_tokens', 'Manage API tokens'), $data['tokens_url'])))
			->addClass('zvc-hint')
	]))->addClass('zvc-card')
]))
	->setId('zvc-result')
	->addClass('zvc-hidden');

(new CHtmlPage())
	->setTitle($data['title'])
	->addItem(
		(new CDiv([$form, $result]))
			->addClass('zvc')
			->setAttribute('data-csrf', $data['csrf'])
			->setAttribute('data-copied', Lang::t('copied', 'Copied'))
	)
	->show();
