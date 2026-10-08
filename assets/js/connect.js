/*
 * ZbxView connect - user page.
 * Not paired: asks zbxview.connect.create for a code as soon as the page
 * opens. Paired: "Pair again" (after a confirm) replaces the token.
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
					? root.dataset.partLabel.replace('{i}', i + 1).replace('{n}', parts.length)
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
			let left = HIDE_AFTER;
			const tick = () => {
				const m = Math.floor(left / 60), s = String(left % 60).padStart(2, '0');
				$('zvc-timer-text').textContent = root.dataset.hides + ' ' + m + ':' + s;
				$('zvc-bar').firstElementChild.style.width = (left / HIDE_AFTER * 100) + '%';
				if (left-- <= 0) {
					hide();
				}
			};
			$('zvc-timer').classList.remove('zvc-hidden');
			tick();
			countdown = setInterval(tick, 1000);
		}

		// The code is a credential: off the screen, then the page shows the state.
		function hide() {
			clearInterval(rotate);
			clearInterval(countdown);
			link = '';
			$('zvc-qr').innerHTML = '';
			$('zvc-open').removeAttribute('href');
			location.reload();
		}

		function create(repair) {
			const button = $('zvc-repair');
			if (button !== null) {
				button.disabled = true;
			}
			say(root.dataset.wait, false);

			const url = new Curl('zabbix.php');
			url.setArgument('action', 'zbxview.connect.create');

			fetch(url.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify({_csrf_token: root.dataset.csrf, repair: repair ? 1 : 0})
			})
				.then((r) => r.json())
				.then((r) => {
					if (r.paired) {
						// Paired meanwhile (another tab): show the state, not a new code.
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
					$('zvc-token-name').textContent = r.token_name || '';
					$('zvc-empty').classList.add('zvc-hidden');
					$('zvc-result').classList.remove('zvc-hidden');
					startTimer();
				})
				.catch((e) => say(String(e), true))
				.finally(() => {
					if (button !== null) {
						button.disabled = false;
					}
				});
		}

		const repair = $('zvc-repair');
		if (repair !== null) {
			repair.addEventListener('click', () => {
				if (window.confirm(root.dataset.confirm)) {
					create(true);
				}
			});
		}

		$('zvc-copy').addEventListener('click', () => {
			if (link === '') {
				return;
			}
			navigator.clipboard.writeText(link).then(() => {
				$('zvc-copy').textContent = root.dataset.copied;
			});
		});

		$('zvc-done').addEventListener('click', hide);

		if (root.dataset.paired !== '1' && root.dataset.api === '1') {
			create(false);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	}
	else {
		init();
	}
})();
