# Changelog

## v1.0.0 (2026-11-20)

First release. Based on Pterodactyl 1.15.2 (stable).

### Added
- **Watchdog** (`zenith:watchdog`) — crash-loop guard, OOM abuse cutoff, sustained CPU auto-suspend, disk warnings. Multi-node aware. Systemd timer runs it every minute.
- **Server Mover** (`zenith:mover`) — safe node-to-node migration with pre-flight checks, auto-suspend during transfer, and a rollback path for the upstream transfer deadlock (#4505).
- **Discord Notifier** (`zenith:notify`) — node down / node high load / server high CPU-RAM-disk alerts with fix suggestions, resolved-message support, per-alert cooldown. Multi-node aware.
- **Zenith Control** admin page — start/stop/restart watchdog and notifier, edit all thresholds, set Discord webhook, send test messages, view live journal logs.
- **Announcements banner** — global banner with info/success/warning/danger types (`zenith:announce`).
- **Brand logo** — v2-style brand logo in the navbar (`zenith:logo`), backported to v1.
- **Daily DB backups** — 14-day retention, .env included (APP_KEY is critical for restore).
- **Health monitor** — services, disk usage, panel/wings reachability, Redis memory; every 5 minutes.
- **One-command installer** (`zenith-install.sh`) — full stack: nginx, PHP 8.3, MariaDB, Redis, SSL, timers, sudoers, cron.

### Security
- Admin-panel service control runs through a sudoers whitelist (exact systemctl commands only).
- Discord webhook validated before save; no secrets in code.
- TLS verification configurable (`ZENITH_VERIFY_TLS`) for self-signed IP installs.
