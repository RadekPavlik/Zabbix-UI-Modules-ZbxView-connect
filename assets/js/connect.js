/*
 * ZbxView connect page: posts the form to zbxview.connect.create, then draws
 * the returned zbxview://add link as a QR code (qrcode.js, MIT, bundled).
 */
(() => {
	const $ = (id) => document.getElementById(id);

	function draw(link) {
		const qr = qrcode(0, 'M');
		qr.addData(link);
		qr.make();
		// SVG scales with the box and stays sharp on any screen.
		$('zvc-qr').innerHTML = qr.createSvgTag({cellSize: 6, margin: 4, scalable: true});
	}

	function init() {
		const root = document.querySelector('.zvc');
		const button = $('zvc-generate');

		if (root === null || button === null) {
			return;
		}

		let link = '';

		button.addEventListener('click', () => {
			$('zvc-error').textContent = '';
			button.disabled = true;

			const url = new Curl('zabbix.php');
			url.setArgument('action', 'zbxview.connect.create');

			fetch(url.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify({
					_csrf_token: root.dataset.csrf,
					name: $('zvc-name').value,
					url: $('zvc-url').value,
					days: document.querySelector('[name="days"]').value,
					self_signed: $('zvc-self-signed').checked ? 1 : 0
				})
			})
				.then((r) => r.json())
				.then((r) => {
					if (r.error !== undefined || r.link === undefined) {
						$('zvc-error').textContent = r.error || 'Error';
						return;
					}

					link = r.link;
					draw(link);
					$('zvc-token-name').textContent = r.token_name;
					$('zvc-expires').textContent = r.expires;
					$('zvc-open').href = link;
					$('zvc-result').classList.remove('zvc-hidden');
					$('zvc-result').scrollIntoView({behavior: 'smooth', block: 'nearest'});
				})
				.catch((e) => {
					$('zvc-error').textContent = String(e);
				})
				.finally(() => {
					button.disabled = false;
				});
		});

		$('zvc-copy').addEventListener('click', () => {
			navigator.clipboard.writeText(link).then(() => {
				$('zvc-copy').textContent = root.dataset.copied;
			});
		});

		// The code is a credential: one click takes it off the screen.
		$('zvc-hide').addEventListener('click', () => {
			link = '';
			$('zvc-qr').innerHTML = '';
			$('zvc-open').removeAttribute('href');
			$('zvc-result').classList.add('zvc-hidden');
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	}
	else {
		init();
	}
})();
