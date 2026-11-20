# Zenith Panel 🛡️

> **Enhanced Pterodactyl** — the game server panel with the features Pterodactyl keeps rejecting.
> Built on [Pterodactyl 1.15.2](https://github.com/pterodactyl/panel) (stable) — everything Pterodactyl does, plus Zenith systems on top.

![panel](https://img.shields.io/badge/panel-1.15.2-blue) ![license](https://img.shields.io/badge/license-MIT-green) ![watchdog](https://img.shields.io/badge/watchdog-built--in-orange) ![discord](https://img.shields.io/badge/discord-notifier-7289DA) ![multi--node](https://img.shields.io/badge/multi--node-supported-success)

---

## ✨ What Zenith adds

| Feature | Stock Pterodactyl | Zenith |
|---|---|---|
| Crash auto-restart | Wings restarts, but **no loop protection** — a broken server thrashes the node all night | ✅ + **crash-loop guard** (3 crashes / 10 min → auto-suspend) |
| Overload cutoff | ❌ an abusive server keeps running | ✅ **sustained CPU abuse → auto-suspend** |
| OOM abuse protection | ❌ | ✅ repeated OOM crashes → suspend |
| Server migration | Native transfer deadlocks on interruption ([#4505](https://github.com/pterodactyl/panel/issues/4505)) | ✅ **`zenith:mover`** — pre-flight checks, auto-suspend during transfer, one-command rollback |
| Node-down alerts | ❌ panel silently slows ([#3936](https://github.com/pterodactyl/panel/issues/3936)) | ✅ **Discord alerts** with fix suggestions |
| High-load alerts | ❌ | ✅ per-server + per-node CPU/RAM/disk alerts, multi-node |
| Admin control | CLI only | ✅ **Zenith Control page** — start/stop services, edit thresholds, set webhook |
| Announcements | ❌ ([#4589](https://github.com/pterodactyl/panel/issues/4589), open since 2022) | ✅ global banner, 4 types |
| Brand logo | ❌ (planned for v2) | ✅ backported to v1 |
| Database backups | ❌ manual | ✅ daily + 14-day retention + integrity verified |
| Health monitoring | ❌ | ✅ services, disk, Redis memory, panel/wings reachability — every 5 min |

*Issue numbers above are real Pterodactyl GitHub issues — these features have been requested for years and never merged. Zenith ships them today.*

## 🚀 Quick Install

Ubuntu 22.04 / 24.04, fresh VPS, root access:

```bash
git clone https://github.com/YumeeKunn/zenith-panel.git
cd zenith-panel
bash zenith-install.sh your-domain.com   # or server IP
```

The installer handles everything: nginx, PHP 8.3, MariaDB, Redis, panel, frontend build, SSL (Let's Encrypt for domains, self-signed for IPs), watchdog + notifier timers, sudoers for admin-panel service control, and daily DB backup cron.

**Create your admin user:**

```bash
cd /var/www/pterodactyl
php artisan p:user:make --no-interaction --name-first=Admin --name-last=User \
  --username=admin --email=admin@example.com --password='StrongPassword!' --admin=1
```

**Wings (node):** follow the [official guide](https://pterodactyl.io/wings/1.0/installing.html) — Zenith uses standard Wings, no fork needed.

## 🎛️ Zenith Control (admin panel)

Log in as admin → **Zenith Control** in the sidebar:

- **🐕 Watchdog** — start / stop / restart, live journal logs
- **📢 Discord Notifier** — start / stop / restart, live journal logs
- **Thresholds** — CPU/RAM/disk warn levels, cooldown, crash limits — saved from the panel, applied next cycle
- **Discord webhook** — paste URL, click *Send Test Message*

No SSH required after install.

## 🐕 Watchdog (auto-protection)

Runs every minute, multi-node aware:

```
crash detected (Wings auto-restarts it)
  └─ 3+ crashes in 10 min      → AUTO-SUSPEND (crash-loop guard)
  └─ RAM >90% before crash     → OOM counter
       └─ 3 OOM crashes        → AUTO-SUSPEND
CPU ≥ threshold sustained 5min → AUTO-SUSPEND (Discord ping included)
disk ≥ 90%                     → daily warning
```

## 📢 Discord Notifier

Checks every 2 minutes across **all nodes** — alerts include fix suggestions:

- 🚨 **Node down** → restart commands, log paths, docker recovery
- 🔔 **Node high CPU/RAM** → find heavy containers, adjust limits
- 📊 **Server high CPU/RAM/disk** → JVM flags, plugins, cleanup
- ✅ **Resolved** → green confirmation when an issue clears

Anti-spam: per-alert cooldown (default 15 min). Setup: Zenith Control → paste webhook → Test.

## 🚚 Server Mover

```bash
# safely move a server to another node:
php artisan zenith:mover <serverId> --to=<nodeId> --allocation=<allocId>

# if the transfer deadlocked (Pterodactyl #4505):
php artisan zenith:mover <serverId> --rollback
```

Pre-flight checks target node reachability, free disk, and allocation availability. Auto-suspends the server during transfer and the rollback path clears stuck state, re-syncs Wings, and unsuspends.

## 🧰 Commands

```bash
php artisan zenith:watchdog --dry-run      # watchdog dry run
php artisan zenith:notify --test           # send a Discord test message
php artisan zenith:notify --cpu-warn=90    # one-off run with custom threshold
php artisan zenith:announce "Maintenance tonight 2AM" --type=warning
php artisan zenith:logo https://.../logo.png
```

## 🔒 Built-in security

Zenith's installer hardens the box by default:

- **UFW firewall** — allowlist-only (web, SSH rate-limited, Wings, game ports)
- **Redis password** — generated + wired into the panel
- **OPcache + JIT** — validate_timestamps off in production, expose_php off
- **nginx rate limiting** — login endpoint (6/min sustained, burst 10) → 429 for brute force
- **HSTS + security headers** + `server_tokens off`
- **2FA required for admins** — enroll at first admin login
- **MariaDB tuning** — 512MB buffer (low-RAM right-sized), O_DIRECT flush, performance_schema off, slow-query log
- **Zero idle RAM** — PHP-FPM ondemand mode: no idle workers, spawns on request, exits after 15s idle
- **Low-RAM profile** — full stack idles at ~300MB; runs comfortably on a 2GB VPS
- **Daily DB backups** — 14-day retention, includes `.env` (APP_KEY)
- **Unattended security upgrades** — automatic patching
- **`.env` locked down** — 640 root:www-data

## 📦 What's different from upstream

```
app/Console/Commands/WatchdogCommand.php        # crash-loop guard, OOM cutoff, CPU cutoff
app/Console/Commands/ServerMoverCommand.php     # safe migration + rollback
app/Console/Commands/DiscordNotifierCommand.php # multi-node health alerts
app/Console/Commands/DiscordSetupCommand.php    # webhook/bot setup wizard
app/Console/Commands/AnnounceCommand.php        # announcement banner
app/Console/Commands/SetLogoCommand.php         # brand logo
app/Http/Controllers/Admin/ZenithController.php # control center
resources/views/admin/zenith.blade.php          # control center UI
config/zenith.php                               # notifications config
zenith-systemd/                                 # timer units
zenith-install.sh                               # one-shot installer
```

## 🆚 Why not Pterodactyl v2?

Pterodactyl 2.0 is in open beta. Zenith builds on **v1.15.2 (stable)** and ships features that don't exist even in v2 (watchdog cutoff, mover, notifier, control center). Porting to v2 is planned once it's stable.

## 📄 License

MIT — same as Pterodactyl. See [LICENSE.md](LICENSE.md). Original panel by [Dane Everitt & contributors](https://github.com/pterodactyl/panel).
