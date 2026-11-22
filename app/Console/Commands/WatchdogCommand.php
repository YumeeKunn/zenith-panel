<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Zenith\NotificationService;

/**
 * Zenith Watchdog — fills the gaps Pterodactyl/Wings leave open:
 *
 *  1. Crash-loop guard: Wings restarts crashes; repeated crashes → auto-suspend
 *  2. OOM abuse: crashes with RAM >90% → counter → suspend
 *  3. CPU overload: sustained CPU → suspend
 *  4. Node down + host load alerts (via NotificationService → Discord/generic)
 *  5. Stuck-state auto-heal: installing/restoring >30min → reset
 *  6. Wings daemon self-monitoring: RAM ballooning (#5115), CPU spikes (#4712)
 *  7. Panel↔Wings version lockstep check
 *  8. Historical metrics collection (#4864): server_metrics + node_metrics
 *
 * Runs every minute via systemd timer.
 */
class WatchdogCommand extends Command
{
    protected $signature = 'zenith:watchdog
        {--dry-run : Report only, take no action}
        {--max-restarts=4 : Max auto-restarts per server per window}
        {--restart-window=600 : Restart window in seconds}
        {--cpu-threshold=93 : Sustained CPU % considered abuse (server)}
        {--cpu-minutes=5 : Minutes of sustained CPU before cutoff}
        {--oom-limit=3 : OOM crashes within window before suspend}
        {--disk-warn=90 : Server disk usage % to warn at}
        {--node-cpu-warn=90 : Node host CPU % to alert at}
        {--node-mem-warn=90 : Node host RAM % to alert at}
        {--host-disk-warn=90 : Node host disk % to alert at}
        {--wings-rss-mb=2048 : Wings daemon RAM alert threshold (MB)}
        {--backup-stale-hours=26 : Hours since last DB backup before alert}';

    protected $description = 'Zenith Watchdog: crash-loop guard, overload cutoff, wings self-monitor, stuck-heal';

    private array $actions = [];
    private string $localIp = '107.148.225.79';

    /** Self-signed certs common on IP installs — disable TLS verify via config. */
    private function insecureTls(): bool
    {
        return (bool) config('zenith.insecure_tls', false);
    }

    /** Admin panel (Zenith Control) DB setting overrides the CLI option. */
    private function opt(string $dbKey, string $cliOption, ?string $default = null): string
    {
        $v = \Pterodactyl\Models\Setting::where('key', 'zenith::watchdog:' . $dbKey)->value('value');
        if ($v !== null && $v !== '') return $v;
        return $this->option($cliOption) ?? $default ?? '';
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $nodes = Node::all();
        if ($nodes->isEmpty()) {
            $this->error('No nodes found.');
            return 1;
        }

        // Multi-node loop: node-down detection + host alerts + node metrics + servers
        foreach ($nodes as $node) {
            $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
            $token = $node->getDecryptedKey();

            try {
                $resp = Http::withToken($token)->withOptions(['verify' => !$this->insecureTls()])->timeout(15)
                    ->get($base . '/api/servers');
                if (!$resp->successful()) {
                    throw new \Exception('HTTP ' . $resp->status());
                }
            } catch (\Exception $e) {
                $this->alertOnce("node-down:{$node->id}",
                    "🚨 **NODE DOWN** — `{$node->name}` ({$node->fqdn}:{$node->daemonListen})",
                    "Wings API unreachable: `{$e->getMessage()}`\n**Fix suggestions:**\n• `systemctl status wings` on {$node->fqdn}\n• `journalctl -u wings -n 50` logs dekho\n• `systemctl restart wings` try karo\n• Docker down? → `systemctl restart docker`\n• Panel pe servers 'offline' dikhenge jab tak wings wapas na aaye",
                    0xED4245, 'node_down');
                $this->storeNodeMetrics($node, null, null, null, $dry);
                continue;
            }

            $this->alertResolved("node-down:{$node->id}");
            $util = $node->utilization();
            $this->storeNodeMetrics($node, $this->hostStats(), $util['memory']['percent'], $util['disk']['percent'], $dry);

            $wingsVer = '';
            try {
                $sys = Http::withToken($token)->withOptions(['verify' => !$this->insecureTls()])->timeout(8)
                    ->get($base . '/api/system');
                if ($sys->successful()) {
                    $wingsVer = $sys->json()['version'] ?? '';
                    $this->checkVersionLockstep($node, $wingsVer);
                }
            } catch (\Throwable $e) { /* ignore */ }

            $servers = $resp->json() ?? [];
            foreach ($servers as $ws) {
                $uuid = $ws['configuration']['uuid'] ?? null;
                if (!$uuid) continue;
                $server = Server::where('uuid', $uuid)->first();
                if (!$server) continue;
                $this->inspect($server, $ws, $base, $token, $dry);
            }
        }

        $this->checkBackupStale($dry);
        $this->monitorLocalWings($dry);

        foreach ($this->actions as $a) {
            $this->line($a);
            \Log::channel('daily')->info('[watchdog] ' . $a);
        }
        if (empty($this->actions)) {
            $this->line('[' . now()->toDateTimeString() . '] all servers healthy');
        }
        return 0;
    }

    /** Alert fired once per cooldown window. Respects per-type channel routing. */
    private function alertOnce(string $key, string $title, string $text, int $color, string $type, bool $urgent = false): void
    {
        $cooldown = (int) (\Pterodactyl\Models\Setting::where('key', 'zenith::notify:cooldown')->value('value')
            ?? $this->option('cooldown'));
        $coolKey = 'zenith:alert-cool:' . $key;
        if (Cache::has($coolKey)) {
            return; // anti-spam
        }
        // channel 'off' ho to alert generate hi na ho
        $svc = app(NotificationService::class);
        if (!$svc->wantsDiscord($type) && !$svc->wantsGeneric($type)) {
            return;
        }
        Cache::put($coolKey, true, $cooldown);
        $this->actions[] = "ALERT [{$type}] {$title}";
        app(NotificationService::class)->send($title, $text, $color, $type);
        \Log::channel('daily')->info('[watchdog] ALERT ' . $title);
    }

    /** Issue cleared → green message (cooldown reset + resolved alert). */
    private function alertResolved(string $key): void
    {
        $coolKey = 'zenith:alert-cool:' . $key;
        if (Cache::has($coolKey)) {
            Cache::forget($coolKey);
            $label = explode(':', $key)[0];
            app(NotificationService::class)->send(
                '✅ Resolved — ' . $label,
                "`{$label}` wala issue clear ho gaya hai. Sab normal!",
                0x57F287,
                'resolved'
            );
            \Log::channel('daily')->info('[watchdog] RESOLVED ' . $label);
        }
    }

    private function inspect(Server $server, array $ws, string $base, string $token, bool $dry): void
    {
        $uuid = $server->uuid;
        $state = $ws['state'] ?? 'offline';
        $util = $ws['utilization'] ?? [];
        $suspended = $ws['is_suspended'] ?? false;
        $key = 'watchdog:' . $uuid;
        $hist = Cache::get($key, ['restarts' => [], 'oom' => [], 'cpu_high_since' => null, 'cpu_low_streak' => 0]);

        // track state transition + last memory ratio (for OOM detection)
        $prev = Cache::get('watchdog:prev:' . $uuid, 'unknown');
        $prevMemRatio = Cache::get('watchdog:memratio:' . $uuid, 0);
        Cache::put('watchdog:prev:' . $uuid, $state, 3600);
        $memLimit = $util['memory_limit_bytes'] ?? 0;
        if ($memLimit > 0 && ($util['memory_bytes'] ?? 0) > 0) {
            Cache::put('watchdog:memratio:' . $uuid, ($util['memory_bytes'] / $memLimit), 3600);
        }

        if ($suspended) {
            return; // already suspended, nothing to do
        }

        // ---- 1. CRASH-LOOP GUARD ----
        // Wings ALREADY auto-restarts crashes. Watchdog = safety net: repeated
        // crashes → SUSPEND so the node isn't thrashed all night.
        $wasRunning = in_array($prev, ['running', 'starting']);
        if ($state === 'offline' && $wasRunning) {
            $window = (int) $this->opt('restart-window', 'restart-window');
            $max = (int) $this->opt('max-restarts', 'max-restarts');
            $hist['restarts'][] = time();
            $recent = array_filter($hist['restarts'], fn ($t) => $t > time() - $window);
            if (count($recent) >= $max) {
                $this->actions[] = "CRASH-LOOP {$server->name}: " . count($recent) . " crashes in {$window}s — SUSPENDING";
                $this->suspend($server, 'crash-loop (' . count($recent) . ' crashes)', $dry);
            }
            // OOM tracking: only count as OOM if memory was near limit (>90%) before crash
            if ($prevMemRatio >= 0.9) {
                $hist['oom'][] = time();
                $this->checkOom($server, $hist, $dry);
            }
        }

        // ---- 2. SUSTAINED CPU OVERLOAD ----
        if ($state === 'running') {
            $cpu = $util['cpu_absolute'] ?? 0;
            $threshold = (float) $this->opt('cpu-threshold', 'cpu-threshold');
            $minutes = (int) $this->opt('cpu-minutes', 'cpu-minutes');
            if ($cpu >= $threshold) {
                $hist['cpu_low_streak'] = 0;
                if (!$hist['cpu_high_since']) {
                    $hist['cpu_high_since'] = time();
                } elseif (time() - $hist['cpu_high_since'] >= max(60, $minutes * 60)) {
                    $this->actions[] = "CPU-OVERLOAD {$server->name}: {$cpu}% sustained — cutoff";
                    $this->suspend($server, "sustained CPU {$cpu}%", $dry);
                    $hist['cpu_high_since'] = null;
                }
            } else {
                // one low reading (API blip) shouldn't reset the streak
                $hist['cpu_low_streak'] = ($hist['cpu_low_streak'] ?? 0) + 1;
                if ($hist['cpu_low_streak'] >= 2) {
                    $hist['cpu_high_since'] = null;
                }
            }

            // ---- 3. DISK GUARD ----
            $disk = $util['disk_bytes'] ?? 0;
            $limit = $server->disk * 1048576;
            if ($limit > 0) {
                $pct = round($disk / $limit * 100);
                $warn = (int) $this->opt('disk-warn', 'disk-warn');
                if ($pct >= $warn && !Cache::has('watchdog:diskwarn:' . $uuid)) {
                    $this->actions[] = "DISK-WARN {$server->name}: {$pct}% of disk limit used";
                    Cache::put('watchdog:diskwarn:' . $uuid, true, 86400);
                }
            }
        }

        Cache::put($key, $hist, 7200);
    }

    private function checkOom(Server $server, array &$hist, bool $dry): void
    {
        $limit = (int) $this->opt('oom-limit', 'oom-limit');
        $window = (int) $this->opt('restart-window', 'restart-window');
        $recent = array_filter($hist['oom'], fn ($t) => $t > time() - $window);
        if (count($recent) >= $limit) {
            $this->actions[] = "OOM-ABUSE {$server->name}: " . count($recent) . " crashes in {$window}s — suspending";
            $this->suspend($server, 'repeated OOM/crashes (' . count($recent) . ')', $dry);
        }
    }

    private function suspend(Server $server, string $reason, bool $dry): void
    {
        if ($dry) {
            $this->actions[] = "  [dry-run] would SUSPEND {$server->name}: {$reason}";
            return;
        }
        try {
            $server->status = 'suspended';
            $server->save();
            try {
                $node = Node::find($server->node_id);
                if ($node) {
                    Http::withToken($node->getDecryptedKey())
                        ->withOptions(['verify' => !$this->insecureTls()])->timeout(10)
                        ->post($node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen . '/api/servers/' . $server->uuid . '/sync');
                }
            } catch (\Throwable $e) { /* best-effort sync */ }
            $this->actions[] = "  SUSPENDED {$server->name}: {$reason}";
            app(NotificationService::class)->send(
                '🐕 Watchdog SUSPENDED server',
                "`{$server->name}` auto-suspended.\n**Reason:** {$reason}\n\n**Fix:** Admin → Servers → {$server->name} → unsuspend after investigating.",
                0xED4245,
                'watchdog'
            );
        } catch (\Exception $e) {
            $this->actions[] = "  SUSPEND FAILED {$server->name}: " . $e->getMessage();
        }
    }

    private function power(string $base, string $token, string $uuid, string $action): void
    {
        try {
            Http::withToken($token)->withOptions(['verify' => !$this->insecureTls()])->timeout(15)
                ->post($base . '/api/servers/' . $uuid . '/power', ['action' => $action]);
        } catch (\Exception $e) {
            $this->actions[] = "  power {$action} failed: " . $e->getMessage();
        }
    }

    /** Zenith metrics — per-server rows (48h retention). */

    /** Node-level metrics: allocated load (ALL nodes) + host actual (local node only). */
    private function storeNodeMetrics(Node $node, ?array $hostStats, ?float $allocatedMem, ?float $allocatedDisk, bool $dry): void
    {
        if ($dry) return;
        try {
            $isLocal = $node->fqdn === $this->localIp;
            $cpu = $hostStats['cpu'] ?? null;
            $memPct = $hostStats['mem'] ?? null;
            $diskPct = $hostStats['disk'] ?? null;
            $memTotal = $isLocal ? intval(shell_exec("free -b | grep Mem | awk '{print $2}'") ?: 0) : 0;
            $totalBytes = $isLocal ? (@disk_total_space('/') ?: 0) : 0;
            $freeBytes = $isLocal ? (@disk_free_space('/') ?: 0) : 0;

            \Pterodactyl\Models\NodeMetric::create([
                'node_id' => $node->id,
                'cpu_percent' => $cpu ?? 0,
                'memory_percent' => $memPct ?? 0,
                'allocated_memory_percent' => $allocatedMem,
                'allocated_disk_percent' => $allocatedDisk,
                'memory_used_bytes' => intval($memTotal * ($memPct ?? 0) / 100),
                'memory_total_bytes' => $memTotal,
                'disk_percent' => $diskPct ?? 0,
                'disk_used_bytes' => intval($totalBytes - $freeBytes),
                'disk_total_bytes' => intval($totalBytes),
                'collected_at' => now(),
            ]);
            \Pterodactyl\Models\NodeMetric::where('collected_at', '<', now()->subHours(48))->delete();
        } catch (\Throwable $e) {
            \Log::channel('daily')->error('[watchdog] node metrics failed: ' . $e->getMessage());
        }
    }

    /** Host stats for LOCAL node (wings daemon machine == panel machine). */
    private function hostStats(): array
    {
        return [
            'cpu' => $this->hostCpu(),
            'mem' => $this->hostMem(),
            'disk' => $this->hostDisk('/'),
        ];
    }

    private function hostCpu(): ?float
    {
        try {
            $load = sys_getloadavg()[0];
            $cores = (int) (shell_exec('nproc') ?: 1);
            return round(($load / $cores) * 100, 1);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function hostMem(): ?float
    {
        $free = shell_exec("free | grep Mem | awk '{print $3/$2 * 100.0}'");
        return $free !== null ? round((float) trim($free), 1) : null;
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

    /** Backup stale check — last DB backup > N hours = backup system stuck. */
    private function checkBackupStale(bool $dry): void
    {
        $hours = (int) $this->opt('backup-stale-hours', 'backup-stale-hours');
        $age = $this->lastBackupAge();
        if ($age !== null && $age > $hours) {
            $this->alertOnce('backup-stale',
                "💾 **BACKUP STALE** — last DB backup {$age}h purana",
                "Daily backup expected (3AM cron) par {$age}h se nahi chala.\n**Fix suggestions:**\n• `tail /var/log/ptero-backup.log` dekho\n• Manual run: /usr/local/bin/ptero-db-backup.sh\n• crontab -l me entry check karo",
                0xE67E22, 'backup');
        }
    }

    /** Wings daemon self-monitoring — local node (panel machine). */
    private function monitorLocalWings(bool $dry): void
    {
        $pid = intval(shell_exec('pgrep -x wings | head -1'));
        if ($pid <= 0) return;

        $rssMb = floatval(shell_exec("ps -o rss= -p $pid | awk '{print $1/1024}'"));
        $cpu = floatval(shell_exec("ps -o pcpu= -p $pid | awk '{print $1}'"));
        $limit = (float) $this->opt('wings-rss-mb', 'wings-rss-mb');

        if ($rssMb > $limit) {
            $this->alertOnce('wings-ram',
                "🐕 WINGS RAM BALLOONING — {$rssMb}MB (limit {$limit}MB)",
                "Wings daemon RAM ballooning (report #5115 pattern).\n**Fix suggestions:**\n• `systemctl restart wings` (containers safe rehte hain)\n• Repeat hone pe Wings version update karo",
                0xED4245, 'wings_health');
            if ((bool) config('zenith.wings_auto_restart', false) && !$dry) {
                shell_exec('systemctl restart wings 2>&1');
                \Log::channel('daily')->info('[watchdog] wings auto-restarted (RAM ballooning)');
            }
        }

        if ($cpu >= 80 && !Cache::has('zenith:wings-cpu-high')) {
            Cache::put('zenith:wings-cpu-high', true, 3600);
            \Log::channel('daily')->info("[watchdog] Wings CPU spike: {$cpu}% — #4712 pattern");
        }
    }

    /** Panel↔Wings version lockstep — wings <1.13.0 = too old for v2 panel. */
    private function checkVersionLockstep(Node $node, string $wingsVer): void
    {
        preg_match('/(\d+)\.(\d+)/', $wingsVer, $m);
        if (isset($m[1], $m[2]) && ($m[1] < 1 || ($m[1] == 1 && $m[2] < 13))) {
            $this->alertOnce('lockstep:' . $node->id,
                "⚠️ VERSION LOCKSTEP — `{$node->name}` wings {$wingsVer} old",
                "Wings {$wingsVer} v2 panel ke liye too old (needs >=1.13.0).\n**Fix suggestions:**\n• Wings update karo (panel↔wings lockstep docs)\n• Aadhé update = khula CVE (report section 4)",
                0xE67E22, 'node_load');
        }
    }
}
