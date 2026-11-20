# Zenith Panel 🛡️

> **Enhanced Pterodactyl** — game server panel with the features Pterodactyl keeps saying "no" to.
> Based on [Pterodactyl 1.x](https://github.com/pterodactyl/panel) — everything Pterodactyl does, plus Zenith systems on top.

![status](https://img.shields.io/badge/panel-1.15.2-blue) ![license](https://img.shields.io/badge/license-MIT-green) ![watchdog](https://img.shields.io/badge/watchdog-built_in-orange) ![discord](https://img.shields.io/badge/discord-notifier-7289DA)

---

## ✨ What Zenith adds

| Feature | Pterodactyl | Zenith |
|---|---|---|
| Crash auto-restart | Wings only, no loop protection | ✅ + **crash-loop guard** (3 crashes → auto-suspend) |
| Overload cutoff | ❌ server chalti rahegi | ✅ **sustained CPU abuse → auto-suspend** |
| OOM abuse protection | ❌ | ✅ repeated OOM crashes → suspend |
| Safe server migration | Transfer deadlock (#4505) | ✅ **`zenith:mover`** + pre-flight checks + rollback |
| Node down alerts | ❌ | ✅ **Discord notifications** with fix suggestions |
| Admin panel control | ❌ CLI only | ✅ **Zenith Control page** — start/stop/edit everything |
| Announcements | ❌ (#4589 open since 2022) | ✅ banner with 4 types |
| Brand logo | ❌ (v2 only) | ✅ backported to v1 |
| DB backups | ❌ manual | ✅ daily + 14-day retention + health monitor |
| Failing gracefully | Panel slow when node down (#3936) | ✅ health monitor + watchdog catches it |

*(Issues referenced are real Pterodactyl GitHub issues — ye features maangi gayi saalon se, kabhi merge nahi hui.)*

## 🚀 Quick Install

Ubuntu 22.04 / 24.04 fresh VPS pe:

```bash
git clone https://github.com/YumeeKunn/zenith-panel.git
cd zenith-panel
bash zenith-install.sh your-domain.com   # ya server IP
```

Script khud karega: nginx + PHP 8.3 + MariaDB + Redis + panel + frontend build + SSL (domain pe Let's Encrypt, IP pe self-signed) + watchdog/notifier timers + DB backup cron.

**Admin user banao:**
```bash
cd /var/www/pterodactyl
php artisan p:user:make --no-interaction --name-first=Admin --name-last=User \
  --username=admin --email=admin@example.com --password='StrongPassword!' --admin=1
```

**Wings (node) install:** [official guide](https://pterodactyl.io/wings/1.0/installing.html) follow karo — Zenith Wings bhi standard hai.

## 🎛️ Zenith Control (Admin Panel)

Admin login → **Zenith Control** (sidebar me shield icon):

- **🐕 Watchdog** — Start / Stop / Restart, live journal logs
- **📢 Discord Notifier** — Start / Stop / Restart, live journal logs
- **Thresholds edit** — CPU/RAM/disk warn %, cooldown, crash limits — panel se save, next cycle apply
- **Discord webhook** — URL paste + "Send Test Message"
- No CLI, no SSH — sab UI se

## 🐕 Watchdog (auto-protection)

Har minute chalta hai:

```
crash detected (wings restart karta hai)
  └─ 3+ crashes / 10 min  → AUTO-SUSPEND (crash-loop guard)
  └─ crash se pehle RAM >90% limit → OOM counter
       └─ 3 OOM crashes → AUTO-SUSPEND
CPU ≥ threshold sustained 5 min → AUTO-SUSPEND
disk ≥ 90% → warn (daily once)
```

Thresholds admin panel se tune ho jaate hain.

## 📢 Discord Notifier

Har 2 min check — alerts with **fix suggestions**:

- 🚨 **Node down** → "systemctl restart wings", "journalctl -u wings", docker restart steps
- 🔔 **Node high CPU/RAM** → docker stats, limits kam karo
- 📊 **Server high CPU/RAM/disk** → JVM flags, plugins, cleanup steps
- ✅ **Resolved** → green message jab issue clear ho

Anti-spam: same alert 15 min cooldown. Setup: **Zenith Control → Discord webhook → paste → Test**.

## 🚚 Server Mover

```bash
# server ko doosre node pe safely shift karo:
php artisan zenith:mover <serverId> --to=<nodeId> --allocation=<allocId>

# transfer deadlock (Pterodactyl #4505) aaya to:
php artisan zenith:mover <serverId> --rollback
```

Pre-flight: target reachable, disk space, allocation free, status normal. Transfer ke dauran auto-suspend, rollback pe state-clear + wings re-sync + unsuspend.

## 🧰 Useful Commands

```bash
php artisan zenith:watchdog --dry-run        # watchdog dry run
php artisan zenith:notify --test             # discord test message
php artisan zenith:notify --cpu-warn=90      # one-off run custom threshold
php artisan zenith:announce "Maintenance tonight 2AM" --type=warning
php artisan zenith:logo https://.../logo.png # brand logo
```

## 📦 What's inside (vs upstream)

```
app/Console/Commands/WatchdogCommand.php        # crash-loop guard, OOM, CPU cutoff
app/Console/Commands/ServerMoverCommand.php     # safe migration + rollback
app/Console/Commands/DiscordNotifierCommand.php # node/server health alerts
app/Console/Commands/DiscordSetupCommand.php    # webhook/bot setup wizard
app/Console/Commands/AnnounceCommand.php        # announcement banner
app/Console/Commands/SetLogoCommand.php         # brand logo
app/Http/Controllers/Admin/ZenithController.php # control center
resources/views/admin/zenith.blade.php          # control center UI
resources/scripts/components/AnnouncementBanner.tsx
config/zenith.php                               # notifications config
zenith-systemd/                                 # timer units
zenith-install.sh                               # one-shot installer
```

## 🆚 Why not Pterodactyl v2?

Pterodactyl 2.0 open beta me hai. Zenith **v1.15.2 (stable)** base use karta hai + features jo v2 me bhi nahi hain (watchdog cutoff, mover, notifier, control center). v2 stable hone ke baad port karne ka plan hai.

## 📄 License

MIT — same as Pterodactyl. Pterodactyl ka original license [yahan](https://github.com/pterodactyl/panel/blob/1.0-develop/LICENSE.md).

## 🙏 Credits

- [Pterodactyl Panel](https://github.com/pterodactyl/panel) — original panel (Dane Everitt & contributors)
- Zenith enhancements built on real production pain points
