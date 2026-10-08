/*
 * ZbxView connect page.
 * Not paired: asks zbxview.connect.create for a code as soon as the page
 * opens. Paired: "Pair again" (after a confirm) replaces the token.
 * The returned zbxview://add link is drawn as a QR code (qrcode.js, MIT, bundled).
 */
(() => {
	const $ = (id) => document.getElementById(id);

	function draw(text) {
		const qr = qrcode(0, 'M');
		qr.addData(text);
		qr.make();
		// SVG scales with the box and stays sharp on any screen.
		$('zvc-qr').innerHTML = qr.createSvgTag({cellSize: 6, margin: 4, scalable: true});
	}

	// A link with a client certificate is too big for one readable code: the
	// parts are shown in turn and the app collects them in any order.
	let timer = null;

	function show(parts, label) {
		clearInterval(timer);
		timer = null;
		let i = 0;
		const step = () => {
			draw(parts[i]);
			$('zvc-part').textContent = parts.length > 1
				? label.replace('{i}', i + 1).replace('{n}', parts.length)
				: '';
			i = (i + 1) % parts.length;
		};
		step();
		$('zvc-qr').classList.toggle('zvc-qr-multi', parts.length > 1);
		if (parts.length > 1) {
			timer = setInterval(step, 1000);
		}
	}

	function init() {
		const root = document.querySelector('.zvc');

		if (root === null) {
			return;
		}

		// One line for progress and errors; red only for errors.
		const say = (text, is_error) => {
			const el = $('zvc-error');
			el.textContent = text;
			el.classList.toggle('zvc-error-on', !!is_error);
		};
		const error = (text) => say(text, true);

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
						error(r.error || 'Error');
						return;
					}
					say('', false);
					show(Array.isArray(r.parts) && r.parts.length ? r.parts : [r.link], root.dataset.partLabel);
					$('zvc-open').href = r.link;
					if ($('zvc-status') !== null) {
						$('zvc-status').classList.add('zvc-hidden');
					}
					$('zvc-result').classList.remove('zvc-hidden');
				})
				.catch((e) => error(String(e)))
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

		// The code is a credential: "Done" takes it off the screen and shows the state.
		$('zvc-done').addEventListener('click', () => {
			clearInterval(timer);
			$('zvc-qr').innerHTML = '';
			$('zvc-open').removeAttribute('href');
			location.reload();
		});

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
