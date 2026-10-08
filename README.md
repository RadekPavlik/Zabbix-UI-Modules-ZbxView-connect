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

| Field | Meaning |
|---|---|
| Name in the app | Label of the server in the app (default: the frontend's server name) |
| Server address | URL the **phone** reaches this Zabbix at — prefilled with the browser's, change it when phones come through a relay / Cloudflare. Remembered per user. |
| Token valid for | 30 days, 90 days (default), 1 year, never |
| Self-signed certificate | Tells the app to pin the server's certificate on first connect |

*Create QR code* makes a token named `ZbxView <date time>` for the user
(listed under *User settings → API tokens*, where it can be disabled or
deleted) and draws the code in the browser (bundled
[qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator), MIT —
nothing is loaded from the internet). On the phone itself, *Open in the app*
does the same through the `zbxview://` link.

## Security

- The code **is a credential**: whoever scans it signs in as you, with your
  permissions. The page says so, shows the code only once, and *Hide* clears
  it. Prefer a short validity; delete tokens of phones you no longer use.
- Token creation goes through the regular API as the signed-in user, with
  the frontend's CSRF protection.

## QR format

```
zbxview://add?url=<https://host/path>&name=<label>&auth=token&token=<api token>&self_signed=0|1
```

URL-encoded (RFC 3986). `auth=password&user=<name>` is also understood by the
app (the user then types the password); a password is never put in a code.

## Development

`tools/check_create.php <frontend url> <token file>` runs the token-creating
controller against a live Zabbix (as the token's user), checks the link and
deletes the token again. `tools/render_page.php [lang]` renders the page body
for a look outside a logged-in browser.
