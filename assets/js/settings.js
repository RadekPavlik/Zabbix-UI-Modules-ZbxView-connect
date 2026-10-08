/*
 * ZbxView connect - administration page.
 * One Save stores everything (zbxview.connect.settings.update, action=save);
 * "Check certificate" validates a chosen file without storing it. The file is
 * read in the browser and sent base64-encoded; the server checks it.
 * The preview builds the same link the pairing page does - with a
 * placeholder token and key of the real length - and splits it like
 * Pairing::split(), so the admin sees the codes users will get.
 */
(() => {
	const $ = (id) => document.getElementById(id);
	const PART_MAX = 400;

	function init() {
		const root = document.querySelector('.zvc-settings');

		if (root === null) {
			return;
		}

		const T = JSON.parse(root.dataset.t);
		let preview = JSON.parse(root.dataset.preview);
		let file = null;          // {b64, name} once chosen
		let hasCert = root.dataset.hasCert === '1';

		const say = (id, text, is_error) => {
			const el = $(id);
			el.textContent = text;
			el.classList.toggle('zvc-error-on', !!is_error);
		};

		// --- server certificate radio cards --------------------------------
		const radios = Array.from(document.querySelectorAll('[name="zvc_server_cert"]'));
		const serverCert = () => (radios.find((r) => r.checked) || {value: '0'}).value;
		radios.forEach((r) => r.addEventListener('change', () => {
			radios.forEach((x) => x.closest('label').classList.toggle('is-on', x.checked));
			draw();
		}));

		// --- host chips -----------------------------------------------------
		const box = $('zvc-s-hosts');
		const field = $('zvc-s-host-input');
		let hosts = (box.dataset.hosts || '').split(',').map((h) => h.trim()).filter(Boolean);

		function renderChips() {
			box.querySelectorAll('.zvc-chip').forEach((c) => c.remove());
			hosts.forEach((h, i) => {
				const chip = document.createElement('span');
				chip.className = 'zvc-chip zvc-mono';
				chip.textContent = h;
				const x = document.createElement('button');
				x.type = 'button';
				x.textContent = '×';
				x.addEventListener('click', () => {
					hosts.splice(i, 1);
					renderChips();
					draw();
				});
				chip.appendChild(x);
				box.insertBefore(chip, field);
			});
		}

		function addHost() {
			field.value.split(/[,;\s]+/).map((h) => h.trim().toLowerCase()).filter(Boolean).forEach((h) => {
				if (!hosts.includes(h)) {
					hosts.push(h);
				}
			});
			field.value = '';
			renderChips();
			draw();
		}

		field.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' || e.key === ',' || e.key === ' ') {
				e.preventDefault();
				addHost();
			}
			else if (e.key === 'Backspace' && field.value === '' && hosts.length) {
				hosts.pop();
				renderChips();
				draw();
			}
		});
		field.addEventListener('blur', addHost);
		box.addEventListener('click', () => field.focus());
		const hostList = () => {
			addHost();
			return hosts.join(',');
		};

		// --- required switch ------------------------------------------------
		const required = $('zvc-s-required');
		required.addEventListener('change', () => {
			if (!required.checked && hasCert && !window.confirm(T.remove)) {
				required.checked = true;
				return;
			}
			$('zvc-s-cert-body').classList.toggle('zvc-off', !required.checked);
			draw();
		});

		// --- file -----------------------------------------------------------
		const setFileBox = (state, name, meta) => {
			const fileBox = $('zvc-s-file-box');
			fileBox.classList.toggle('is-bad', state === 'bad');
			fileBox.classList.toggle('is-empty', state === 'empty');
			$('zvc-s-file-name').textContent = name;
			$('zvc-s-file-meta').textContent = meta;
		};
		const describe = (c) => ['EC ' + (c.curve || ''), 'CN=' + (c.cn || ''),
			c.valid_to ? T.valid_until + ' ' + c.valid_to : ''].filter(Boolean).join(' · ');

		$('zvc-s-pick').addEventListener('click', () => $('zvc-s-file').click());
		$('zvc-s-file').addEventListener('change', () => {
			const f = $('zvc-s-file').files[0];
			if (!f) {
				return;
			}
			const reader = new FileReader();
			reader.onload = () => {
				file = {b64: String(reader.result).replace(/^data:[^,]*,/, ''), name: f.name};
				setFileBox('empty', f.name, '');
				check();
			};
			reader.readAsDataURL(f);
		});

		function post(body) {
			const url = new Curl('zabbix.php');
			url.setArgument('action', 'zbxview.connect.settings.update');

			return fetch(url.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify(Object.assign({_csrf_token: root.dataset.csrf}, body))
			}).then((r) => r.json());
		}

		const certBody = () => file === null ? {} : {cert: file.b64, cert_name: file.name,
			password: $('zvc-s-password').value};

		function check() {
			if (file === null) {
				say('zvc-s-cert-msg', T.no_file, true);
				return;
			}
			say('zvc-s-cert-msg', '…', false);
			post(Object.assign({action: 'check', hosts: hostList()}, certBody()))
				.then((r) => {
					if (r.error !== undefined || !r.ok) {
						setFileBox('bad', file.name, r.error || 'Error');
						say('zvc-s-cert-msg', '', false);
						return;
					}
					setFileBox('ok', file.name, describe(r.cert));
					say('zvc-s-cert-msg', T.checked, false);
					preview = r.preview;
					draw();
				})
				.catch((e) => say('zvc-s-cert-msg', String(e), true));
		}

		$('zvc-s-check').addEventListener('click', check);
		$('zvc-s-password').addEventListener('change', () => {
			if (file !== null) {
				check();
			}
		});

		// --- save / discard -------------------------------------------------
		$('zvc-s-save').addEventListener('click', () => {
			const button = $('zvc-s-save');
			button.disabled = true;
			say('zvc-s-msg', '…', false);
			post(Object.assign({
				action: 'save',
				url: $('zvc-s-url').value,
				name: $('zvc-s-name').value,
				server_cert: serverCert(),
				cert_required: required.checked ? '1' : '0',
				hosts: hostList()
			}, required.checked ? certBody() : {}))
				.then((r) => {
					if (r.error !== undefined || !r.ok) {
						say('zvc-s-msg', r.error || 'Error', true);
						return;
					}
					say('zvc-s-msg', T.saved, false);
					location.reload();
				})
				.catch((e) => say('zvc-s-msg', String(e), true))
				.finally(() => {
					button.disabled = false;
				});
		});
		$('zvc-s-discard').addEventListener('click', () => location.reload());

		// --- preview --------------------------------------------------------
		const enc = (s) => encodeURIComponent(s).replace(/[!'()*]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase());

		function buildParts() {
			const url = ($('zvc-s-url').value.trim() || root.dataset.urlAuto).replace(/\/+$/, '');
			const name = $('zvc-s-name').value.trim() || root.dataset.nameAuto;
			let link = 'zbxview://add?url=' + enc(url) + '&name=' + enc(name) + '&auth=token&token='
				+ 'preview'.padEnd(64, '0') + '&self_signed=' + serverCert();
			if (required.checked && preview.cc) {
				link += '&cc=' + preview.cc + '&ck=' + 'A'.repeat(preview.ck_len);
				const ch = hosts.join(',');
				if (ch !== '') {
					link += '&ch=' + enc(ch);
				}
			}
			if (link.length <= PART_MAX + 200) {
				return [link];
			}
			const count = Math.ceil(link.length / PART_MAX), size = Math.ceil(link.length / count);
			const parts = [];
			for (let i = 0; i < count; i++) {
				parts.push('ZBXV1:prevw:' + (i + 1) + '/' + count + ':' + link.substr(i * size, size));
			}
			return parts;
		}

		let parts = [], shown = 0, rotate = null;

		function showPart(i) {
			shown = i;
			const qr = qrcode(0, 'M');
			qr.addData(parts[i]);
			qr.make();
			$('zvc-qr').innerHTML = qr.createSvgTag({cellSize: 6, margin: 4, scalable: true});
			$('zvc-qr').classList.toggle('zvc-qr-multi', parts.length > 1);
			$('zvc-p-tabs').querySelectorAll('button').forEach((b, j) => b.classList.toggle('is-on', j === i));
			$('zvc-part').textContent = parts.length === 1 ? T.single
				: (i === 0 ? T.part1 : T.partn.replace('{i}', i + 1));
		}

		function draw() {
			parts = buildParts();
			const tabs = $('zvc-p-tabs');
			tabs.innerHTML = '';
			if (parts.length > 1) {
				parts.forEach((_, i) => {
					const b = document.createElement('button');
					b.type = 'button';
					b.textContent = String(i + 1);
					b.addEventListener('click', () => {
						clearInterval(rotate);
						rotate = null;
						showPart(i);
					});
					tabs.appendChild(b);
				});
			}
			$('zvc-p-note').classList.toggle('zvc-hidden', parts.length < 2);
			clearInterval(rotate);
			showPart(Math.min(shown, parts.length - 1));
			if (parts.length > 1) {
				rotate = setInterval(() => showPart((shown + 1) % parts.length), 1500);
			}

			$('zvc-p-url').textContent = ($('zvc-s-url').value.trim() || root.dataset.urlAuto).replace(/\/+$/, '');
			$('zvc-p-name').textContent = $('zvc-s-name').value.trim() || root.dataset.nameAuto;
			$('zvc-p-sc').textContent = serverCert() === '1' ? T.self : T.public;
			const n = hosts.length || 1;
			$('zvc-p-cc').textContent = required.checked && preview.cc ? T.yes_hosts.replace('{n}', n) : T.no;
		}

		['zvc-s-url', 'zvc-s-name'].forEach((id) => $(id).addEventListener('input', draw));
		renderChips();
		draw();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	}
	else {
		init();
	}
})();
