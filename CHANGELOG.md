# Changelog

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
