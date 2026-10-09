# Changelog

## 1.5.0 — 2026-10-09

- **A code nobody scans is dropped again.** The token behind a QR code is
  kept only once a phone has used it (its `lastaccess`). The page asks the
  server every 3 s; when the phone signs in, the code disappears at once with
  "Phone connected" and the device list reloads. After the 5-minute window,
  on *Hide now*, or when the page is left, an unused token is deleted
  (`mode=discard`, sent as a beacon on leaving); a stale one is swept on the
  next open of the page or any pairing call (`Pairing::settle()`, window +
  60 s slack). Devices are only listed once paired.
- **New QR code keeps the old token working** until the phone has scanned
  the new one (the new token waits as "<device> ~"; `settle()` then deletes
  the old token and gives the new one the device's name). If nobody scans,
  the device stays as it was.
- Note: tokens of earlier versions that no phone ever used (`lastaccess` 0)
  are dropped on the first open as well.
- `tools/check_create.php` covers pending → discard, pairing by using the
  token, and the deferred repair.

## 1.4.0 — 2026-10-08

- **Several phones per user.** *Mobile connect* lists the user's devices
  (name, paired, last used), each with its own never-expiring token
  ("ZbxView · <device>"). Per device: **New QR code** (new token first, then
  the old one of that device only) and **Remove**. **Add another device**
  (optional name, default "Phone") pairs one more even when one exists.
  The first device is still paired as soon as the page opens. Tokens of 1.x
  ("ZbxView <date>") show as "Phone (<date>)".
- `tools/check_create.php` touches only devices it creates itself and checks
  that the others (a real phone) stay as they are.

## 1.3.3 — 2026-10-08

- "Discard changes" is a regular bordered button (it read as plain text).

## 1.3.2 — 2026-10-08

- Admin entry and page renamed to **Mobile configuration** (cs Konfigurace
  telefonu, lv Tālruņa konfigurācija); phone icon after the text as in 1.3.1.

## 1.3.1 — 2026-10-08

- The user page is now called **Mobile connect** (cs: Připojení telefonu,
  lv: Tālruņa savienojums) - menu entry and page title.
- The phone icon sits after the menu text instead of before it.

## 1.3.0 — 2026-10-08

- **New look for both pages, following the user's Zabbix theme** (light,
  dark and both high-contrast themes).
- **Admin page moved to Administration → Mobile app.** Two numbered cards
  (connection in the QR code; client certificate with a *Required* switch,
  file box with *Replace*, password, host chips, *Check certificate*), one
  *Save* / *Discard changes* for everything, and a live **preview** of the
  codes users scan (placeholder token and key - the page never carries the
  real key). The certificate's file name and key curve are shown.
- **Connect application**: server card (name, address, paired / last used,
  *Pair again*) and a code card with a **5-minute auto-hide countdown**,
  token facts, *Open in the app*, *Copy link*, *Hide now*.
- **Phone icon** in front of both menu entries (global `menu.css`).

## 1.2.0 — 2026-10-08

- **Administration → General → ZbxView connect** (super admins): server
  address, name and server-certificate handling for the QR code, and the
  shared **client certificate import** right in the GUI - upload .p12/.pfx
  or .pem, password, hosts. The certificate is checked before it is stored
  (EC key, key matches, password, readable) and the page shows its CN,
  validity and hosts; "Remove certificate" forgets it.
- `tools/check_settings.php` runs the save action against a live Zabbix and
  restores the module config afterwards.

## 1.1.0 — 2026-10-08

- **No form any more.** First open of *Connect application* creates the code
  right away; afterwards the page shows *Paired* / *Last used by the app* and a
  single **Pair again** button (with a confirm).
- **One connection per user, never expires.** Token `ZbxView <date time>` with
  no expiry; *Pair again* creates the new token first and only then deletes the
  user's older ZbxView tokens, so the old phone stops working and nothing is
  lost if creation fails. Reloading the page or a second tab never creates a
  token.
- Server address, name and certificate handling come from the module
  **config** (`url`, `name`, `self_signed`), not from the user.
- **Certificate pin in the code**: with `self_signed=auto` (default) the
  module checks the certificate at the address; when this server does not
  trust it, the code carries `pin=<SHA-256 of the certificate DER>`, the value
  the app stores as its certificate pin. `1` always pins, `0` never.
- Globus defaults in manifest.json: `https://zabbix-app.globus.cz`,
  `S4000ZABBIXKOO`, `self_signed=0`. Empty keys in the saved Zabbix config fall
  back to manifest.json, so a module registered by 1.0.x picks them up.
- **Client certificate (mTLS) in the code**: one shared EC certificate set by
  an admin (`tools/set_client_cert.php`, config `client_cert*`) travels with
  the token. The link is then split into 3 QR codes shown in turn; the app
  (0.49.0+) collects them. RSA certificates are refused with a clear message.
- `tools/check_create.php` runs the whole flow (pair, reload, pair again) and
  cleans up; refuses to touch a user who already has ZbxView tokens.

## 1.0.1 — 2026-10-08

- Renamed to **Zabbix-UI-Modules-ZbxView-connect** (repository and module
  directory), display name "ZbxView connect". Module id `zbxviewconnect` is
  unchanged, so an installed module only needs its directory renamed.
- `tools/` scripts refuse to run outside the command line: the module
  directory is served by the web server.

## 1.0.0 — 2026-10-07

- *User settings → Connect application*: API token + QR code for the ZbxView /
  Zabbix mobile app (0.47.0+). Validity 30/90/365 days or never, server
  address remembered per user, self-signed flag, copy / open-in-app / hide.
- Czech, English and Latvian.
