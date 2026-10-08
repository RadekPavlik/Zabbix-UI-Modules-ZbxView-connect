/*
 * ZbxView connect - user page (Mobile connect).
 * Devices: every phone has its own token. With no device yet the first one is
 * paired as soon as the page opens; "Add another device" pairs one more,
 * "New QR code" re-pairs one device, the bin removes it
 * (zbxview.connect.create, mode=first|add|repair|remove).
 * The code is shown for HIDE_AFTER seconds, then taken off the screen.
 * A link with a client certificate comes as parts shown in turn.
 * QR drawing: qrcode.js (MIT, bundled).
 */
(() => {
	const $ = (id) => document.getElementById(id);
	const HIDE_AFTER = 300;

	function draw(text) {
		const qr = qrcode(0, 'M');
		qr.addData(text);
		qr.make();
		// SVG scales with the box and stays sharp on any screen.
		$('zvc-qr').innerHTML = qr.createSvgTag({cellSize: 6, margin: 4, scalable: true});
	}

	function init() {
		const root = document.querySelector('.zvc');

		if (root === null || $('zvc-result') === null) {
			return;
		}

		const T = JSON.parse(root.dataset.t);
		let link = '';
		let rotate = null;
		let countdown = null;

		// One line for progress and errors; red only for errors.
		const say = (text, is_error) => {
			const el = $('zvc-error');
			el.textContent = text;
			el.classList.toggle('zvc-error-on', !!is_error);
		};

		function show(parts) {
			clearInterval(rotate);
			let i = 0;
			const step = () => {
				draw(parts[i]);
				$('zvc-part').textContent = parts.length > 1
					? T.part.replace('{i}', i + 1).replace('{n}', parts.length)
					: '';
				i = (i + 1) % parts.length;
			};
			step();
			$('zvc-qr').classList.toggle('zvc-qr-multi', parts.length > 1);
			if (parts.length > 1) {
				rotate = setInterval(step, 1000);
			}
		}

		function startTimer() {
			clearInterval(countdown);
			let left = HIDE_AFTER;
			const tick = () => {
				const m = Math.floor(left / 60), s = String(left % 60).padStart(2, '0');
				$('zvc-timer-text').textContent = T.hides + ' ' + m + ':' + s;
				$('zvc-bar').firstElementChild.style.width = (left / HIDE_AFTER * 100) + '%';
				if (left-- <= 0) {
					hide();
				}
			};
			$('zvc-timer').classList.remove('zvc-hidden');
			tick();
			countdown = setInterval(tick, 1000);
		}

		// The code is a credential: off the screen, then the page shows the devices.
		function hide() {
			clearInterval(rotate);
			clearInterval(countdown);
			link = '';
			$('zvc-qr').innerHTML = '';
			$('zvc-open').removeAttribute('href');
			location.reload();
		}

		function call(body) {
			const url = new Curl('zabbix.php');
			url.setArgument('action', 'zbxview.connect.create');

			return fetch(url.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify(Object.assign({_csrf_token: root.dataset.csrf}, body))
			}).then((r) => r.json());
		}

		const buttons = () => Array.from(document.querySelectorAll('.js-repair, .js-remove, #zvc-add'));
		const busy = (on) => buttons().forEach((b) => {
			b.disabled = on;
		});

		function pair(body) {
			busy(true);
			say(T.wait, false);
			call(body)
				.then((r) => {
					if (r.paired) {
						// Paired meanwhile (another tab): show the devices, not a new code.
						location.reload();
						return;
					}
					if (r.error !== undefined || r.link === undefined) {
						say(r.error || 'Error', true);
						return;
					}
					say('', false);
					link = r.link;
					show(Array.isArray(r.parts) && r.parts.length ? r.parts : [r.link]);
					$('zvc-open').href = r.link;
					$('zvc-device').textContent = r.device || '';
					$('zvc-token-name').textContent = r.token_name || '';
					$('zvc-empty').classList.add('zvc-hidden');
					$('zvc-result').classList.remove('zvc-hidden');
					document.querySelectorAll('.zvc-dev').forEach((row) => {
						row.classList.toggle('is-current', row.dataset.tokenid === String(body.tokenid || ''));
					});
					startTimer();
				})
				.catch((e) => say(String(e), true))
				.finally(() => busy(false));
		}

		document.querySelectorAll('.js-repair').forEach((b) => b.addEventListener('click', () => {
			if (window.confirm(T.repair.replace('{name}', b.dataset.name))) {
				pair({mode: 'repair', tokenid: b.dataset.tokenid});
			}
		}));

		document.querySelectorAll('.js-remove').forEach((b) => b.addEventListener('click', () => {
			if (!window.confirm(T.remove.replace('{name}', b.dataset.name))) {
				return;
			}
			busy(true);
			call({mode: 'remove', tokenid: b.dataset.tokenid})
				.then((r) => {
					if (r.error !== undefined) {
						say(r.error, true);
						return;
					}
					location.reload();
				})
				.catch((e) => say(String(e), true))
				.finally(() => busy(false));
		}));

		$('zvc-add').addEventListener('click', () => {
			pair({mode: 'add', device: $('zvc-device-name').value});
		});
		$('zvc-device-name').addEventListener('keydown', (e) => {
			if (e.key === 'Enter') {
				e.preventDefault();
				$('zvc-add').click();
			}
		});

		$('zvc-copy').addEventListener('click', () => {
			if (link === '') {
				return;
			}
			navigator.clipboard.writeText(link).then(() => {
				$('zvc-copy').textContent = T.copied;
			});
		});

		$('zvc-done').addEventListener('click', hide);

		if (root.dataset.paired !== '1' && root.dataset.api === '1') {
			pair({mode: 'first'});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	}
	else {
		init();
	}
})();
