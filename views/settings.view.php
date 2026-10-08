<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data
 */

use Modules\ZbxViewConnect\Includes\Lang;
use Modules\ZbxViewConnect\Includes\Ui;

$head = static fn(?string $step, string $title, string $sub = '', $right = null) => (new CDiv([
	$step !== null ? (new CDiv($step))->addClass('zvc-step') : null,
	(new CDiv([
		(new CTag('h2', true, $title))->addClass('zvc-h'),
		$sub !== '' ? (new CDiv($sub))->addClass('zvc-sub') : null
	]))->addClass('zvc-grow'),
	$right
]))->addClass('zvc-card-head');

$field = static fn(string $label, $control, string $hint = '') => (new CDiv([
	(new CTag('label', true, $label))->addClass('zvc-label'),
	$control,
	$hint !== '' ? (new CDiv($hint))->addClass('zvc-hint') : null
]))->addClass('zvc-field');

$input = static fn(string $id, string $value, string $placeholder = '', string $type = 'text') =>
	(new CTag('input', false))
		->setAttribute('type', $type)
		->setId($id)
		->addClass('zvc-input')
		->setAttribute('value', $value)
		->setAttribute('placeholder', $placeholder)
		->setAttribute('spellcheck', 'false')
		->setAttribute('autocomplete', $type === 'password' ? 'new-password' : 'off');

$radio = static fn(string $value, string $title, string $text) => (new CTag('label', true, [
	(new CTag('input', false))
		->setAttribute('type', 'radio')
		->setAttribute('name', 'zvc_server_cert')
		->setAttribute('value', $value)
		->setAttribute($data['server_cert'] === $value ? 'checked' : 'data-off', $data['server_cert'] === $value ? 'checked' : '1'),
	new CDiv([new CTag('b', true, $title), new CSpan($text)])
]))->addClass($data['server_cert'] === $value ? 'is-on' : null);

// --- 1: what the QR code carries ------------------------------------------
$connection = (new CDiv([
	$head('1', Lang::t('s_conn_title', 'Connection in the QR code'),
		Lang::t('s_conn_intro', 'Users only scan the code - the address and name come from here.')),
	(new CDiv([
		(new CDiv([
			$field(Lang::t('url', 'Server address'), $input('zvc-s-url', $data['url'], $data['url_auto']),
				Lang::t('s_url_hint', 'Where phones reach this Zabbix (e.g. through Cloudflare). Empty = the address in the browser.')),
			$field(Lang::t('name', 'Name in the app'), $input('zvc-s-name', $data['name'], $data['name_auto']),
				Lang::t('s_name_hint', 'Empty = the frontend server name.'))
		]))->addClass('zvc-row'),
		(new CDiv([
			(new CTag('label', true, Lang::t('s_cert_server', 'Server certificate')))->addClass('zvc-label'),
			(new CDiv([
				$radio('0', Lang::t('ss_public', 'Public CA'),
					Lang::t('ss_public_hint', 'Never accept self-signed. E.g. behind Cloudflare.')),
				$radio('1', Lang::t('ss_self', 'Self-signed'),
					Lang::t('ss_self_hint', 'The app trusts the server\'s own certificate. On-prem only.'))
			]))->addClass('zvc-choice')
		]))->addClass('zvc-field')->addStyle('margin-bottom: 0;')
	]))->addClass('zvc-card-body')
]))->addClass('zvc-card');

// --- 2: shared client certificate -----------------------------------------
$cert = $data['cert'];
$cert_ok = $cert['set'] && !isset($cert['error']);
$meta = $cert_ok
	? implode(' · ', array_filter([
		'EC '.$cert['curve'],
		'CN='.$cert['cn'],
		$cert['valid_to'] !== '' ? Lang::t('s_valid_until', 'valid until').' '.$cert['valid_to'] : ''
	]))
	: ($cert['set'] ? Lang::t('err_cert_'.$cert['error'], 'The client certificate cannot be used.')
		: Lang::t('s_cc_choose', 'No certificate yet - choose a .p12 / .pfx or .pem file.'));

$file_box = (new CDiv([
	(new CDiv(Ui::icon($cert_ok ? 'file_ok' : ($cert['set'] ? 'file_bad' : 'file'))))->addClass('zvc-file-ico')->setId('zvc-s-file-ico'),
	(new CDiv([
		(new CDiv($cert_ok ? ($data['cert_file'] !== '' ? $data['cert_file'] : $cert['cn']) : Lang::t('s_cc_file', 'Certificate file')))
			->addClass('zvc-file-name')->addClass('zvc-mono')->setId('zvc-s-file-name'),
		(new CDiv($meta))->addClass('zvc-file-meta')->setId('zvc-s-file-meta')
	]))->addClass('zvc-grow'),
	(new CSimpleButton($cert['set'] ? Lang::t('s_cc_replace', 'Replace') : Lang::t('s_cc_pick', 'Choose file')))
		->setId('zvc-s-pick')->addClass('zvc-btn')->addClass('zvc-btn-alt'),
	(new CTag('input', false))->setAttribute('type', 'file')->setId('zvc-s-file')->addClass('zvc-hidden')
		->setAttribute('accept', '.p12,.pfx,.pem,.crt,.key')
]))->setId('zvc-s-file-box')->addClass('zvc-file')
	->addClass($cert_ok ? null : ($cert['set'] ? 'is-bad' : 'is-empty'));

$hosts = (new CDiv((new CTag('input', false))->setAttribute('type', 'text')->setId('zvc-s-host-input')
	->setAttribute('placeholder', '*.example.com')->setAttribute('spellcheck', 'false')))
	->setId('zvc-s-hosts')->addClass('zvc-chips')->setAttribute('data-hosts', $data['cert_hosts']);

$certificate = (new CDiv([
	$head('2', Lang::t('s_cc_title', 'Client certificate (mTLS)'),
		Lang::t('s_cc_sub', 'For a Zabbix behind a gateway that requires a client certificate (e.g. Cloudflare).'),
		(new CTag('label', true, [
			new CSpan(Lang::t('s_cc_required', 'Required')),
			(new CTag('input', false))->setAttribute('type', 'checkbox')->setId('zvc-s-required')
				->setAttribute($cert['set'] ? 'checked' : 'data-off', $cert['set'] ? 'checked' : '1'),
			new CTag('i', true)
		]))->addClass('zvc-switch')
	),
	(new CDiv([
		$file_box,
		(new CDiv([
			$field(Lang::t('s_cc_password', 'Password'), $input('zvc-s-password', '', '', 'password'),
				Lang::t('s_cc_password_hint', 'Of the .p12 file or of an encrypted key. Empty when there is none.')),
			$field(Lang::t('s_cert_hosts', 'Sent to'), $hosts,
				Lang::t('s_cc_hosts_hint', '*.example.com covers subdomains. Empty = the server address above.'))
		]))->addClass('zvc-row'),
		(new CDiv([
			(new CSimpleButton(Lang::t('s_cc_check', 'Check certificate')))->setId('zvc-s-check')
				->addClass('zvc-btn')->addClass('zvc-btn-outline'),
			(new CSpan(Lang::t('s_cc_only_hosts', 'The app sends it only to the hosts above.')))->addClass('zvc-note'),
			(new CSpan())->setId('zvc-s-cert-msg')->addClass('zvc-msg')
		]))->addClass('zvc-actions')
	]))->setId('zvc-s-cert-body')->addClass('zvc-card-body')->addClass($cert['set'] ? null : 'zvc-off')
]))->addClass('zvc-card');

$footer = (new CDiv([
	(new CSimpleButton(Lang::t('save', 'Save')))->setId('zvc-s-save')->addClass('zvc-btn'),
	(new CSimpleButton(Lang::t('s_discard', 'Discard changes')))->setId('zvc-s-discard')
		->addClass('zvc-btn')->addClass('zvc-btn-alt'),
	(new CSpan(Lang::t('s_footer_note', 'Applies to codes generated from now on; phones already set up keep working.')))
		->addClass('zvc-note'),
	(new CSpan())->setId('zvc-s-msg')->addClass('zvc-msg')
]))->addClass('zvc-footer');

// --- preview ---------------------------------------------------------------
$fact = static fn(string $k, $v, string $id) => [
	(new CSpan($k))->addClass('zvc-k'),
	(new CSpan($v))->addClass('zvc-v')->setId($id)
];

$preview = (new CDiv([
	$head(null, Lang::t('s_preview_title', 'What users scan'), '',
		(new CSpan(Lang::t('s_preview', 'Preview')))->addClass('zvc-kicker')),
	(new CDiv([
		(new CDiv([
			(new CDiv())->setId('zvc-qr')->addClass('zvc-qr'),
			(new CDiv())->setId('zvc-p-tabs')->addClass('zvc-tabs'),
			(new CDiv())->setId('zvc-part')->addClass('zvc-part'),
			(new CDiv(Lang::t('s_preview_note', 'The codes rotate on screen. The user keeps the phone pointed until all three are read.')))
				->setId('zvc-p-note')->addClass('zvc-center-note')
		]))->addClass('zvc-qr-box'),
		(new CDiv(array_merge(
			$fact(Lang::t('server', 'Server'), '', 'zvc-p-url'),
			$fact(Lang::t('name', 'Name'), '', 'zvc-p-name'),
			$fact(Lang::t('s_cert_server', 'Server certificate'), '', 'zvc-p-sc'),
			$fact(Lang::t('s_cc_short', 'Client certificate'), '', 'zvc-p-cc')
		)))->addClass('zvc-facts'),
		(new CLink([Ui::icon('external'), Lang::t('s_open_user', 'Open the page users see')],
			(new CUrl('zabbix.php'))->setArgument('action', 'zbxview.connect')->getUrl()))
			->setAttribute('target', '_blank')
			->addClass('zvc-btn')->addClass('zvc-btn-alt')->addClass('zvc-btn-wide')->addStyle('margin-top: 18px;')
	]))->addClass('zvc-card-body')
]))->addClass('zvc-card');

(new CHtmlPage())
	->setTitle($data['title'])
	->setControls((new CSpan(Lang::t('s_active', 'QR sign-in active')))->addClass('zvc-pill')
		->addClass('zvc-vars')->addClass($data['theme']))
	->addItem(
		(new CDiv([
			(new CDiv([
				(new CDiv([$connection, $certificate, $footer]))->addClass('zvc-col'),
				$preview
			]))->addClass('zvc-grid')
		]))
			->addClass('zvc')
			->addClass('zvc-settings')
			->addClass($data['theme'])
			->setAttribute('data-csrf', $data['csrf'])
			->setAttribute('data-preview', json_encode($data['preview']))
			->setAttribute('data-url-auto', $data['url_auto'])
			->setAttribute('data-name-auto', $data['name_auto'])
			->setAttribute('data-has-cert', $cert_ok ? '1' : '0')
			->setAttribute('data-t', json_encode([
				'saved' => Lang::t('saved', 'Saved'),
				'checked' => Lang::t('s_cc_checked', 'Certificate is valid.'),
				'no_file' => Lang::t('s_cc_no_file', 'Choose the certificate file first.'),
				'valid_until' => Lang::t('s_valid_until', 'valid until'),
				'public' => Lang::t('ss_public', 'Public CA'),
				'self' => Lang::t('ss_self', 'Self-signed'),
				'yes_hosts' => Lang::t('s_yes_hosts', 'Yes · {n} host(s)'),
				'no' => Lang::t('no', 'No'),
				'part1' => Lang::t('s_part1', 'Code 1 - connection and sign-in'),
				'partn' => Lang::t('s_partn', 'Code {i} - client certificate'),
				'single' => Lang::t('s_single', 'One code - connection and sign-in'),
				'remove' => Lang::t('s_cc_remove_confirm',
					'Turn the client certificate off? New QR codes will not carry it; phones that already have it keep it.')
			]))
	)
	->show();
