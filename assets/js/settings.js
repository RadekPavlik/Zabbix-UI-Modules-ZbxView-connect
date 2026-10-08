/*
 * ZbxView connect - administration page. Posts JSON to
 * zbxview.connect.settings.update; the certificate file is read in the
 * browser and sent base64-encoded, the server checks it before storing.
 */
(() => {
	const $ = (id) => document.getElementById(id);

	function init() {
		const root = document.querySelector('.zvc-settings');

		if (root === null) {
			return;
		}

		const say = (id, text, is_error) => {
			const el = $(id);
			el.textContent = text;
			el.classList.toggle('zvc-error-on', !!is_error);
		};

		function post(body, msg_id, button) {
			if (button) {
				button.disabled = true;
			}
			say(msg_id, '…', false);

			const url = new Curl('zabbix.php');
			url.setArgument('action', 'zbxview.connect.settings.update');

			return fetch(url.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify(Object.assign({_csrf_token: root.dataset.csrf}, body))
			})
				.then((r) => r.json())
				.then((r) => {
					if (r.error !== undefined || !r.ok) {
						say(msg_id, r.error || 'Error', true);
						return null;
					}
					say(msg_id, root.dataset.saved, false);
					return r;
				})
				.catch((e) => {
					say(msg_id, String(e), true);
					return null;
				})
				.finally(() => {
					if (button) {
						button.disabled = false;
					}
				});
		}

		$('zvc-s-save').addEventListener('click', () => {
			post({
				section: 'settings',
				url: $('zvc-s-url').value,
				name: $('zvc-s-name').value,
				self_signed: document.querySelector('[name="self_signed"]').value
			}, 'zvc-s-msg', $('zvc-s-save'));
		});

		// File -> base64 in the browser (works for binary .p12 and text .pem).
		const readFile = (file) => new Promise((resolve, reject) => {
			const reader = new FileReader();
			reader.onload = () => resolve(String(reader.result).replace(/^data:[^,]*,/, ''));
			reader.onerror = () => reject(reader.error);
			reader.readAsDataURL(file);
		});

		$('zvc-s-import').addEventListener('click', () => {
			const file = $('zvc-s-file').files[0];

			if (!file) {
				say('zvc-s-cert-msg', root.dataset.noFile, true);
				return;
			}

			readFile(file)
				.then((b64) => post({
					section: 'certificate',
					cert: b64,
					password: $('zvc-s-password').value,
					hosts: $('zvc-s-hosts').value
				}, 'zvc-s-cert-msg', $('zvc-s-import')))
				.then((r) => {
					if (r !== null) {
						// Show the stored certificate's details; the password field is cleared.
						$('zvc-s-password').value = '';
						location.reload();
					}
				})
				.catch((e) => say('zvc-s-cert-msg', String(e), true));
		});

		const remove = $('zvc-s-remove');

		if (remove !== null) {
			remove.addEventListener('click', () => {
				if (!window.confirm(root.dataset.confirmRemove)) {
					return;
				}
				post({section: 'remove_cert'}, 'zvc-s-cert-msg', remove).then((r) => {
					if (r !== null) {
						location.reload();
					}
				});
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	}
	else {
		init();
	}
})();
