# ZbxView connect

Zabbix frontend module that connects the **ZbxView / Zabbix mobile app** to a
server with a QR code — no typing of URLs and tokens on a phone, no MDM needed.

*User settings → Connect application* creates an API token for the signed-in
user and shows it as a QR code. In the app: **Add server → Scan QR code**,
check the summary, **Add server**. Done.

## Requirements

- Zabbix **6.4 – 8.0** (module manifest v2). Tested on 7.0.
- The user's role must allow **Manage API tokens** (the menu entry is hidden
  otherwise) and **API access** (the page warns when it is off).
- App version **0.47.0** or newer.

## Install

```bash
cp -r Zabbix-UI-Modules-ZbxView-connect /usr/share/zabbix/modules/
chown -R apache:apache /usr/share/zabbix/modules/Zabbix-UI-Modules-ZbxView-connect   # www-data on Debian/Ubuntu
```

*Administration → General → Modules → Scan directory*, enable **ZbxView
connect**. Or through the API:

```json
module.create  {"id": "zbxviewconnect", "relative_path": "modules/Zabbix-UI-Modules-ZbxView-connect", "status": 1}
```

## What the page does

- **First open**: the QR code appears immediately - no questions. In the app:
  *Add server → Scan QR code*.
- **Later**: the page shows when the app was paired and last used, and a
  **Pair again** button (new phone, reinstalled app, lost phone). Pairing
  again replaces the token: the previous phone stops working.
- The token (`ZbxView <date time>`, under *User settings → API tokens*) never
  expires. One per user.

## Configuration (admin, once)

Module `config` (manifest defaults, changeable with `module.update`; a key
left empty in Zabbix falls back to manifest.json).


| Key | Default | Meaning |
|---|---|---|
| `url` | *empty* = the browser's address | URL the **phone** reaches Zabbix at (relay / Cloudflare) |
| `name` | *empty* = frontend server name | Server label in the app |
| `self_signed` | `auto` | `auto`: pin the certificate when this server does not trust it; `1`: always pin; `0`: never |

```json
module.update {"moduleid": "<id>", "config": {"url": "https://zabbix.example.com", "name": "Production", "self_signed": "auto"}}
```

## Client certificate (mTLS) in the code

For servers behind a gateway that requires a client certificate (e.g.
Cloudflare mTLS), the module can hand one **shared** certificate to the app
during pairing. Set it once as an admin:

```bash
php tools/set_client_cert.php https://zabbix.example.com <admin token file> client.p12 <password> zabbix.example.com
php tools/set_client_cert.php https://zabbix.example.com <admin token file> --remove
```

The tool checks the certificate first and keeps the other config keys
(`client_cert` = PEM with certificate and key, or base64 of a PKCS#12 bundle;
`client_cert_password`; `client_cert_hosts` = comma-separated hosts it is sent
to, empty = the server's host).

- **EC keys only** (e.g. P-256). Certificate and key then make ~1.1 kB of
  link; an RSA key does not fit and the page says so.
- Such a link is too big for one readable QR code (125x125 modules failed
  scan tests), so the page shows it as **3 codes in turn**, one per second,
  each 77x77 modules (read 20/20 in noisy-camera tests at 425 px). The app
  (0.49.0+) collects them in any order, shows "Scanned 1 of 3", reassembles
  the link and saves the certificate like an imported one.
- Without a configured certificate nothing changes: one plain code.

Part format: `ZBXV1:<id>:<index>/<count>:<chunk>`; the chunks in index order
are the `zbxview://add` link. Link parameters `cc` / `ck` = base64url (no
padding) of the certificate DER / PKCS#8 key DER, `ch` = hosts.

## QR format

```
zbxview://add?url=<https://host/path>&name=<label>&auth=token&token=<api token>&self_signed=0|1[&pin=<sha256 hex>][&cc=..&ck=..&ch=..]
```

URL-encoded (RFC 3986). `auth=password&user=<name>` is also understood by the
app (the user then types the password); a password is never put in a code.

## Development

`tools/check_create.php <frontend url> <token file> [config url]` runs the
pairing flow against a live Zabbix (as the token's user): first pairing,
reload, pair again; checks the links and the certificate pin, then deletes
the tokens it made. `tools/render_page.php [lang]` renders the page body
for a look outside a logged-in browser.
