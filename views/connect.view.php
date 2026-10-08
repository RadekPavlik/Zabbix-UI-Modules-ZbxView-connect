<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

use Modules\ZbxViewConnect\Includes\Lang;

// Not paired: the code is created as soon as the page opens (connect.js).
// Paired: just the state and "Pair again".
$status = (new CDiv([
	(new CTag('h2', true, Lang::t('paired_title', 'The app is connected')))->addClass('zvc-h'),
	(new CDiv([
		(new CSpan(Lang::t('paired_since', 'Paired')))->addClass('zvc-k'),
		new CSpan($data['created']),
		(new CSpan(Lang::t('last_used', 'Last used by the app')))->addClass('zvc-k'),
		new CSpan($data['lastaccess'])
	]))->addClass('zvc-facts'),
	(new CDiv(Lang::t('paired_intro',
		'The connection stays valid until you pair again. New phone, reinstalled app or a lost phone: pair again - the old connection stops working.'
	)))->addClass('zvc-intro'),
	(new CDiv(
		(new CButton('zvc-repair', Lang::t('repair', 'Pair again')))->setId('zvc-repair')
	))->addClass('zvc-actions')
]))
	->setId('zvc-status')
	->addClass($data['paired'] ? 'zvc-card' : 'zvc-card zvc-hidden');

$result = (new CDiv([
	(new CTag('h2', true, Lang::t('result_title', 'Scan in the app')))->addClass('zvc-h'),
	(new CDiv(Lang::t('result_intro', 'In the app tap Add server → Scan QR code.')))->addClass('zvc-intro'),
	(new CDiv())->setId('zvc-qr')->addClass('zvc-qr'),
	(new CDiv())->setId('zvc-part')->addClass('zvc-part'),
	(new CDiv(Lang::t('secret_warn',
		'The code signs in as you. Do not share it or leave it on screen; it is shown only now.'
	)))->addClass('zvc-warn'),
	(new CDiv([
		(new CLink(Lang::t('open_on_phone', 'Open in the app')))->setId('zvc-open')->addClass('zvc-btn-link'),
		(new CSimpleButton(Lang::t('done', 'Done')))->setId('zvc-done')->addClass(ZBX_STYLE_BTN_ALT)
	]))->addClass('zvc-actions')
]))
	->setId('zvc-result')
	->addClass('zvc-card zvc-hidden');

// Progress and errors: outside the cards, so visible before the code is drawn.
$message = (new CDiv())->setId('zvc-error')->addClass('zvc-error');

$no_api = $data['api_access'] ? null : (new CDiv(Lang::t('no_api',
	'Your user role does not allow API access, so the app could not sign in. Ask your Zabbix administrator.'
)))->addClass('zvc-warn zvc-card');

(new CHtmlPage())
	->setTitle($data['title'])
	->addItem(
		(new CDiv([$no_api, $message, $status, $result]))
			->addClass('zvc')
			->setAttribute('data-csrf', $data['csrf'])
			->setAttribute('data-paired', $data['paired'] ? '1' : '0')
			->setAttribute('data-api', $data['api_access'] ? '1' : '0')
			->setAttribute('data-confirm', Lang::t('repair_confirm',
				'Pair again? The phone connected now will stop working.'))
			->setAttribute('data-wait', Lang::t('wait', 'Creating the code…'))
			->setAttribute('data-part-label', Lang::t('part_label',
				'Code {i} of {n} - keep the phone on it until the app has read all of them'))
	)
	->show();
