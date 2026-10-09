/*
 * ZbxView connect - user page (Mobile connect).
 * Devices: every phone has its own token. With no device yet the first one is
 * paired as soon as the page opens; "Add another device" pairs one more,
 * "New QR code" re-pairs one device, the bin removes it
 * (zbxview.connect.create, mode=first|add|repair|remove).
 * While the code is on screen the page asks every POLL_EVERY seconds whether
 * a phone has used the token (mode=status); as soon as one has, the code
 * goes and the device list reloads. After the pairing window (data-window
 * seconds, same as the server's) - or "Hide now", or leaving the page - a
 * code nobody used is dropped again (mode=discard).
 * A link with a client certificate comes as parts shown in turn.
 * QR drawing: qrcode.js (MIT, bundled).
 */
(() => {
	const $ = (id) => document.getElementById(id);
	const POLL_EVERY = 3;

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
		const HIDE_AFTER = parseInt(root.dataset.window, 10) || 300;
		let link = '';
		let rotate = null;
		let countdown = null;
		let poll = null;
		// The token of the code on screen until a phone has used it.
		let pending = null;

		// One line for progress and errors; red for errors, green for success.
		const say = (text, is_error, is_ok) => {
			const el = $('zvc-error');
			el.textContent = text;
			el.classList.toggle('zvc-error-on', !!is_error);
			el.classList.toggle('zvc-ok-on', !!is_ok);
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
					hide(T.timed_out);
				}
			};
			$('zvc-timer').classList.remove('zvc-hidden');
			tick();
			countdown = setInterval(tick, 1000);
		}

		// Did a phone use the token yet? Then the code has done its job.
		function startPoll() {
			clearInterval(poll);
			poll = setInterval(() => {
				if (pending === null) {
					return;
				}
				const tokenid = pending;
				call({mode: 'status', tokenid})
					.then((r) => {
						if (pending !== tokenid) {
							return;
						}
						if (r.paired) {
							pending = null;
							stop();
							say(T.paired_ok, false, true);
							// The device list now has the phone on it.
							setTimeout(() => location.reload(), 1200);
						}
						else if (r.gone) {
							pending = null;
							hide(T.timed_out);
						}
					})
					.catch(() => {});
			}, POLL_EVERY * 1000);
		}

		function stop() {
			clearInterval(rotate);
			clearInterval(countdown);
			clearInterval(poll);
			link = '';
			$('zvc-qr').innerHTML = '';
			$('zvc-open').removeAttribute('href');
			$('zvc-timer').classList.add('zvc-hidden');
		}

		// The code is a credential: off the screen, and a token no phone has
		// used yet is dropped on the server too.
		function hide(message) {
			stop();
			if (pending !== null) {
				call({mode: 'discard', tokenid: pending}).catch(() => {});
				pending = null;
			}
			$('zvc-result').classList.add('zvc-hidden');
			$('zvc-empty').classList.remove('zvc-hidden');
			document.querySelectorAll('.zvc-dev').forEach((row) => row.classList.remove('is-current'));
			say(message || '', false, false);
		}

		// Leaving the page (close, reload, back): the unused code goes with it.
		window.addEventListener('pagehide', () => {
			if (pending === null || typeof navigator.sendBeacon !== 'function') {
				return;
			}
			const url = new Curl('zabbix.php');
			url.setArgument('action', 'zbxview.connect.create');
			navigator.sendBeacon(url.getUrl(), new Blob(
				[JSON.stringify({_csrf_token: root.dataset.csrf, mode: 'discard', tokenid: pending})],
				{type: 'application/json'}
			));
			pending = null;
		});

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
			// One code at a time: a previous unused one is dropped first.
			if (pending !== null) {
				hide('');
			}
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
					pending = r.tokenid || null;
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
					startPoll();
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

		$('zvc-done').addEventListener('click', () => hide(''));

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
