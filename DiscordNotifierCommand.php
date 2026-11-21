<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/**
 * Zenith Discord Notifier — node/server health alerts to Discord.
 *
 * Monitors:
 *   - Node DOWN (wings API unreachable / server unreachable)
 *   - Node HIGH LOAD (host CPU/RAM via wings system endpoint)
 *   - Per-server HIGH LOAD (sustained CPU/RAM/disk near limits)
 *   - Suggestion: "kya fix karna chahiye" per alert
 *
 * Anti-spam: alert cooldown per key (default 15min). Green "resolved"
 * message when an active alert clears.
 *
 * Config (.env):
 *   ZENITH_DISCORD_WEBHOOK=       (option A — easiest)
 *   ZENITH_DISCORD_BOT_TOKEN=     (option B)
 *   ZENITH_DISCORD_CHANNEL_ID=    (option B)
 *   ZENITH_NOTIFY_COOLDOWN=900    (optional, seconds)
 */
class DiscordNotifierCommand extends Command
{
    protected $signature = 'zenith:notify
        {--test : Send a test message and exit}
        {--cooldown=900 : Seconds between repeated alerts for same key}
        {--cpu-warn=85 : Server CPU % to alert at}
        {--mem-warn=90 : Server memory % to alert at}
        {--disk-warn=90 : Server disk % to alert at}
        {--node-cpu-warn=90 : Host CPU % to alert at}
        {--node-mem-warn=90 : Host memory % to alert at}';

    protected $description = 'Zenith Discord Notifier: node down / load alerts + fix suggestions';

    private array $alerts = [];
    private ?string $webhook = null;
    private ?string $botToken = null;
    private ?string $channelId = null;

    public function handle(): int
    {
        $this->webhook = \Pterodactyl\Models\Setting::where('key', 'zenith::discord:webhook')->value('value') ?: config('zenith.discord_webhook');
        $this->botToken = config('zenith.discord_bot_token');
        $this->channelId = config('zenith.discord_channel_id');

        if (empty($this->webhook) && empty($this->botToken)) {
            $this->error('No Discord config. Set ZENITH_DISCORD_WEBHOOK (or bot token+channel) in .env');
            return 1;
        }

        if ($this->option('test')) {
            $this->send('Zenith Panel Monitor', 'Test message — notifications working! :white_check_mark:', 0x57F287);
            $this->info('test message sent');
            return 0;
        }

        $this->checkNode();
        $this->checkServers();

        // deliver alerts
        foreach ($this->alerts as $a) {
            $this->line($a['text']);
            \Log::channel('daily')->info('[zenith-notify] ' . $a['text']);
            $this->send($a['title'], $a['text'], $a['color'], $a['type'] ?? 'general');
        }

        // resolved messages
        $this->checkResolved();

        if (empty($this->alerts)) {
            $this->line('[' . now()->toDateTimeString() . '] no issues detected');
        }
        return 0;
    }

    private function checkNode(): void
    {
        $nodes = Node::all();
        foreach ($nodes as $node) {
            $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
            $downKey = 'node-down:' . $node->id;

            try {
                $resp = Http::withToken($node->getDecryptedKey())
                    ->withOptions(['verify' => !$this->insecureTls()])->timeout(8)
                    ->get($base . '/api/system');
                if (!$resp->successful()) {
                    throw new \Exception('HTTP ' . $resp->status());
                }
                $sys = $resp->json();
                Cache::put('zenith:node-up:' . $node->id, true, 300);

                // host load (absolute from wings system endpoint is absent in v1 API)
                // fall back to docker/host metrics via local shell if same machine
                $hostCpu = $this->hostCpu();
                $hostMem = $this->hostMem();
                if ($hostCpu !== null && $hostCpu >= (float) (app(\Pterodactyl\Services\Zenith\NotificationService::class)->threshold('node_cpu') ?? $this->option('node-cpu-warn'))) {
                    $this->alertOnce(
                        "node-cpu:{$node->id}",
                        "🔔 **Node HIGH CPU** — `{$node->name}` host CPU **{$hostCpu}%**",
                        "CPU sustained above " . $this->option('node-cpu-warn') . "%.\n**Fix suggestions:**\n• `docker stats` se heavy container dhundo\n• `ptero:watchdog --cpu-threshold=90` cutoffs already chal rahe hain\n• Permanent fix: node me aur CPU / limits kam karo\n• Load spike temporary hai to wait (watchdog suspends repeat offenders)",
                        0xE67E22
                    );
                }
                if ($hostMem !== null && $hostMem >= (float) (app(\Pterodactyl\Services\Zenith\NotificationService::class)->threshold('node_ram') ?? $this->option('node-mem-warn'))) {
                    $this->alertOnce(
                        "node-ram:{$node->id}",
                        "🔔 **Node HIGH RAM** — `{$node->name}` host RAM **{$hostMem}%**",
                        "RAM usage above " . $this->option('node-mem-warn') . "%.\n**Fix suggestions:**\n• `docker stats --no-stream` se sabse heavy server dhundo\n• Us server ki memory limit kam karo (admin → server → build)\n• OOM kills se bachne ke liye watchdog OOM-abuse auto-suspend on hai",
                        0xE67E22
                    );
                }
                // Host disk check (root + wings volumes)
                $hostDisk = $this->hostDisk('/');
                if ($hostDisk !== null && $hostDisk >= (float) (app(\Pterodactyl\Services\Zenith\NotificationService::class)->threshold('host_disk') ?? 90)) {
                    $this->alertOnce(
                        "node-disk:{$node->id}",
                        "💾 **Host DISK FULL** — `{$node->name}` root disk **{$hostDisk}%**",
                        "Disk {$hostDisk}% used.\n**Fix suggestions:**\n• `docker system prune -a` purane images hatao\n• `/var/lib/pterodactyl/volumes` me bade server files check karo\n• `/var/backups/pterodactyl` purane backup delete karo\n• Disk expand ya cleanup karwao",
                        0xE67E22
                    );
                }

                // Backup freshness — last backup 26h+ purana = backup system stuck
                $lastBackup = $this->lastBackupAge();
                if ($lastBackup !== null && $lastBackup > 26) {
                    $this->alertOnce(
                        "backup-stale",
                        "💾 **BACKUP STALE** — last DB backup {$lastBackup}h purana",
                        "Daily backup expected (3AM cron) par {$lastBackup}h se nahi chala.\n**Fix suggestions:**\n• `tail /var/log/ptero-backup.log` dekho\n• Manual run: /usr/local/bin/ptero-db-backup.sh\n• crontab -l me entry check karo",
                        0xE67E22
                    );
                }
            } catch (\Exception $e) {
                Cache::forget('zenith:node-up:' . $node->id);
                $this->alertOnce(
                    $downKey,
                    "🚨 **NODE DOWN** — `{$node->name}` ({$node->fqdn}:{$node->daemonListen})",
                    "Wings API unreachable: `{$e->getMessage()}`\n**Fix suggestions:**\n• `systemctl status wings` on {$node->fqdn}\n• `journalctl -u wings -n 50` logs dekho\n• `systemctl restart wings` try karo\n• Docker down? → `systemctl restart docker` (containers restart honge)\n• Panel pe servers 'offline' dikhenge jab tak wings wapas na aaye",
                    0xED4245,
                    true // node down = urgent, shorter cooldown nahi, full spam protection
                );
            }
        }
    }

    private function checkServers(): void
    {
        // Multi-node: check servers on every node
        $nodes = Node::all();
        if ($nodes->isEmpty()) return;

        // Admin panel (Zenith Control) DB settings override CLI defaults
        $cfg = fn (string $key, string $opt, ?float $def = null) => (float) ($this->dbSetting($key) ?? ($this->option($opt) ?: $def));
        $cpuWarn = (float) (app(\Pterodactyl\Services\Zenith\NotificationService::class)->threshold('server_cpu') ?? $this->option('cpu-warn'));
        $memWarn = (float) (app(\Pterodactyl\Services\Zenith\NotificationService::class)->threshold('server_ram') ?? $this->option('mem-warn'));
        $diskWarn = (float) (app(\Pterodactyl\Services\Zenith\NotificationService::class)->threshold('server_disk') ?? $this->option('disk-warn'));

        foreach ($nodes as $node) {
            $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
            try {
                $resp = Http::withToken($node->getDecryptedKey())
                    ->withOptions(['verify' => !$this->insecureTls()])->timeout(8)
                    ->get($base . '/api/servers');
                if (!$resp->successful()) continue;
            } catch (\Exception $e) {
                continue; // node down already alerted by checkNode()
            }

            foreach ($resp->json() ?? [] as $ws) {
            $uuid = $ws['configuration']['uuid'] ?? null;
            if (!$uuid) continue;
            $server = Server::where('uuid', $uuid)->first();
            if (!$server) continue;
            if ($ws['is_suspended'] ?? false) continue;

            $util = $ws['utilization'] ?? [];
            $state = $ws['state'] ?? 'offline';
            if (!in_array($state, ['running', 'starting'])) continue;

            $name = $server->name;
            $memLimit = $util['memory_limit_bytes'] ?? 0;
            if ($memLimit <= 0) continue;

            $memPct = round(($util['memory_bytes'] / $memLimit) * 100);
            $cpuPct = round($util['cpu_absolute'] ?? 0);
            $diskPct = $server->disk > 0 ? round((($util['disk_bytes'] ?? 0) / ($server->disk * 1048576)) * 100) : 0;

            if ($cpuPct >= $cpuWarn) {
                $this->alertOnce(
                    "server-cpu:{$uuid}",
                    "📊 **Server HIGH CPU** — `{$name}` **{$cpuPct}%** (limit {$server->cpu}%)",
                    "CPU {$cpuPct}% sustained near/at limit.\n**Fix suggestions:**\n• Server console me `top`/lag spike check karo\n• World/chunks bade ho to pregen ya view-distance kam karo\n• CPU limit badhao (admin → build) ya server restart\n• Repeat offender? watchdog auto-suspend karega ({$cpuWarn}%+ sustained)",
                    0xE67E22
                );
            }
            if ($memPct >= $memWarn) {
                $this->alertOnce(
                    "server-ram:{$uuid}",
                    "📊 **Server HIGH RAM** — `{$name}` **{$memPct}%** ({$server->memory}MB limit)",
                    "RAM {$memPct}% — OOM crash ka risk!\n**Fix suggestions:**\n• JVM flags me Xmx check karo (panel startup config)\n• Memory limit badhao ya unnecessary plugins hatao\n• OOM hua to watchdog crash-counter hit karega (3 crashes = suspend)",
                    0xE67E22
                );
            }
            if ($diskPct >= $diskWarn) {
                $this->alertOnce(
                    "server-disk:{$uuid}",
                    "📊 **Server DISK** — `{$name}` **{$diskPct}%** ({$server->disk}MB limit)",
                    "Disk {$diskPct}% full.\n**Fix suggestions:**\n• Purane backups/logs delete karo (file manager)\n• `du -sh` se bade folders dhundo\n• Disk limit badhao ya server archive karo",
                    0xE67E22
                );
            }
        }
        }
    }

    private function checkResolved(): void
    {
        $active = Cache::get('zenith:active-alerts', []);
        $nowActive = [];
        foreach ($this->alerts as $a) {
            $nowActive[] = $a['key'];
        }
        foreach ($active as $key) {
            if (!in_array($key, $nowActive)) {
                Cache::forget('zenith:alert-cool:' . $key);
                $label = str_replace(['node-down:', 'node-load:', 'srv-cpu:', 'srv-mem:', 'srv-disk:'], '', $key);
                $this->send('✅ Resolved', "`{$label}` wala issue clear ho gaya hai. Sab normal!", 0x57F287, 'resolved');
            }
        }
        Cache::put('zenith:active-alerts', $nowActive, 7200);
    }

    /** Alert type (routing key) derives from the alert key prefix. */
    private function typeOf(string $key): string
    {
        $map = [
            'node-down' => 'node_down',
            'node-cpu' => 'node_cpu',
            'node-ram' => 'node_ram',
            'host-disk' => 'host_disk',
            'server-cpu' => 'server_cpu',
            'server-ram' => 'server_ram',
            'server-disk' => 'server_disk',
            'backup-stale' => 'backup',
        ];
        $prefix = explode(':', $key)[0] ?? '';
        return $map[$prefix] ?? 'general';
    }

    private function alertOnce(string $key, string $title, string $text, int $color, bool $urgent = false): void
    {
        $cooldown = (int) ($this->dbSetting('notify:cooldown') ?? $this->option('cooldown'));
        $coolKey = 'zenith:alert-cool:' . $key;
        if (Cache::has($coolKey)) {
            return; // still in cooldown, skip repeat alert
        }
        // channel 'off' ho to alert generate hi na ho
        $svc = app(\Pterodactyl\Services\Zenith\NotificationService::class);
        if (!$svc->wantsDiscord($this->typeOf($key)) && !$svc->wantsGeneric($this->typeOf($key))) {
            return; // admin ne ye alert disable kiya hai
        }
        Cache::put($coolKey, true, $cooldown);
        $this->alerts[] = ['key' => $key, 'title' => $title, 'text' => $title . "\n" . $text, 'color' => $color, 'type' => $this->typeOf($key)];
    }

    private function send(string $title, string $text, int $color, string $type = 'general'): void
    {
        $results = app(\Pterodactyl\Services\Zenith\NotificationService::class)->send($title, $text, $color, $type);
        foreach ($results as $channel => $result) {
            if ($result !== 'sent') {
                $this->error("notification [$channel] failed: $result");
            }
        }
        if (empty($results)) {
            $this->error('no notification channel configured or alert set to OFF');
        }
    }

    /** Self-signed certs common on IP installs — disable TLS verify via config. */
    private function insecureTls(): bool
    {
        return (bool) config('zenith.insecure_tls', false);
    }

    private function dbSetting(string $short): ?string
    {
        $v = \Pterodactyl\Models\Setting::where('key', 'zenith::' . $short)->value('value');
        return ($v !== null && $v !== '') ? $v : null;
    }

    private function hostDisk(string $path): ?float
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (!$total || !$free) return null;
        return round((($total - $free) / $total) * 100, 1);
    }

    private function lastBackupAge(): ?float
    {
        $files = glob('/var/backups/pterodactyl/panel_*.sql.gz');
        if (empty($files)) return null;
        $latest = max(array_map('filemtime', $files));
        return round((time() - $latest) / 3600, 1);
    }

    private function hostCpu(): ?float
    {
        try {
            $load = sys_getloadavg()[0];
            $cores = (int) (shell_exec('nproc') ?: 1);
            return round(($load / $cores) * 100, 1);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function hostMem(): ?float
    {
        $free = shell_exec('free | grep Mem | awk \'{print $3/$2 * 100.0}\'');
        return $free !== null ? round((float) trim($free), 1) : null;
    }
}
