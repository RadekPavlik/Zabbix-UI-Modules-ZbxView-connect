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

$self_signed = (new CSelect('self_signed'))
	->setId('zvc-s-self-signed')
	->addOptions(CSelect::createOptionsFromArray([
		'auto' => Lang::t('ss_auto', 'Automatic - pin it when this server does not trust the certificate'),
		'1' => Lang::t('ss_always', 'Always pin the server certificate'),
		'0' => Lang::t('ss_never', 'Never (public CA, e.g. behind Cloudflare)')
	]))
	->setValue($data['self_signed']);

// --- what the QR code carries ---------------------------------------------
$settings = (new CDiv([
	(new CTag('h2', true, Lang::t('s_conn_title', 'Connection in the QR code')))->addClass('zvc-h'),
	(new CDiv(Lang::t('s_conn_intro',
		'Users only scan the code; the address and name come from here.'
	)))->addClass('zvc-intro'),
	$field(Lang::t('url', 'Server address'),
		(new CTextBox('url', $data['url']))->setId('zvc-s-url')->setAttribute('spellcheck', 'false')
			->setAttribute('placeholder', $data['url_auto']),
		Lang::t('s_url_hint', 'The address phones reach this Zabbix at (e.g. through Cloudflare). Empty = the address in the browser.')
	),
	$field(Lang::t('name', 'Name in the app'),
		(new CTextBox('name', $data['name']))->setId('zvc-s-name')->setAttribute('maxlength', 64),
		Lang::t('s_name_hint', 'Empty = the frontend server name.')
	),
	$field(Lang::t('s_cert_server', 'Server certificate'), $self_signed),
	(new CDiv([
		(new CButton('zvc-s-save', Lang::t('save', 'Save')))->setId('zvc-s-save'),
		(new CSpan())->setId('zvc-s-msg')->addClass('zvc-msg')
	]))->addClass('zvc-actions')
]))->addClass('zvc-card');

// --- shared client certificate --------------------------------------------
$cert = $data['cert'];
$state = !$cert['set']
	? (new CDiv(Lang::t('s_cert_none', 'No client certificate - the QR code carries only the sign-in.')))
		->addClass('zvc-intro')
	: (isset($cert['error'])
		? (new CDiv(Lang::t('err_cert_'.$cert['error'], 'The client certificate cannot be used.')))->addClass('zvc-warn')
		: (new CDiv([
			(new CSpan(Lang::t('s_cert_cn', 'Certificate')))->addClass('zvc-k'), new CSpan($cert['cn']),
			(new CSpan(Lang::t('s_cert_valid', 'Valid until')))->addClass('zvc-k'),
			(new CSpan($cert['valid_to']))->addClass($cert['expired'] ? 'zvc-error-on' : null),
			(new CSpan(Lang::t('s_cert_hosts', 'Sent to')))->addClass('zvc-k'), new CSpan($cert['hosts'])
		]))->addClass('zvc-facts'));

$certificate = (new CDiv([
	(new CTag('h2', true, Lang::t('s_cc_title', 'Client certificate (mTLS)')))->addClass('zvc-h'),
	(new CDiv(Lang::t('s_cc_intro',
		'For a Zabbix behind a gateway that requires a client certificate (e.g. Cloudflare). The app gets it with the QR code - shown as three codes in turn - and sends it only to the hosts below. EC keys only (e.g. P-256).'
	)))->addClass('zvc-intro'),
	(new CDiv($state))->setId('zvc-s-cert-state'),
	$field(Lang::t('s_cc_file', 'Certificate file'),
		(new CInput('file', 'cert_file'))->setId('zvc-s-file')->setAttribute('accept', '.p12,.pfx,.pem,.crt,.key'),
		Lang::t('s_cc_file_hint', '.p12 / .pfx with the private key, or .pem with the certificate and the key.')
	),
	$field(Lang::t('s_cc_password', 'Password'),
		(new CInput('password', 'cert_password'))->setId('zvc-s-password')->setAttribute('autocomplete', 'new-password'),
		Lang::t('s_cc_password_hint', 'Of the .p12 file or of an encrypted key. Empty when there is none.')
	),
	$field(Lang::t('s_cert_hosts', 'Sent to'),
		(new CTextBox('cert_hosts', $data['cert_hosts']))->setId('zvc-s-hosts')->setAttribute('spellcheck', 'false')
			->setAttribute('placeholder', 'zabbix.example.com, *.example.com'),
		Lang::t('s_cc_hosts_hint', 'Comma separated; *.example.com covers subdomains. Empty = the server address above.')
	),
	(new CDiv([
		(new CButton('zvc-s-import', Lang::t('s_cc_import', 'Check and save certificate')))->setId('zvc-s-import'),
		$cert['set']
			? (new CSimpleButton(Lang::t('s_cc_remove', 'Remove certificate')))->setId('zvc-s-remove')
				->addClass(ZBX_STYLE_BTN_ALT)
			: null,
		(new CSpan())->setId('zvc-s-cert-msg')->addClass('zvc-msg')
	]))->addClass('zvc-actions')
]))->addClass('zvc-card');

(new CHtmlPage())
	->setTitle($data['title'])
	->addItem(
		(new CDiv([$settings, $certificate]))
			->addClass('zvc')
			->addClass('zvc-settings')
			->setAttribute('data-csrf', $data['csrf'])
			->setAttribute('data-saved', Lang::t('saved', 'Saved'))
			->setAttribute('data-confirm-remove', Lang::t('s_cc_remove_confirm',
				'Remove the client certificate? New QR codes will not carry it; phones that already have it keep it.'))
			->setAttribute('data-no-file', Lang::t('s_cc_no_file', 'Choose the certificate file first.'))
	)
	->show();
