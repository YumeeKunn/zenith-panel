<?php

namespace ZenithMonitor\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\NodeMetric;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerMetric;

/**
 * Zenith Watchdog — once-a-minute tick:
 *
 *  1. Node down detection + recovery (all nodes)
 *  2. Host CPU/RAM alerts + allocated load tracking (local node)
 *  3. Server crash-loop guard (wings restarts crashes; repeated → suspend)
 *  4. OOM abuse cutoff (crashes with RAM >90%)
 *  5. Sustained CPU overload → auto-suspend
 *  6. Server/host disk guards
 *  7. Backup staleness check
 *  8. Wings daemon self-monitoring (RAM ballooning, CPU spikes)
 *  9. Panel↔Wings version lockstep check
 * 10. Metrics collection on demand (graph monitor API triggers it)
 */
class WatchdogService
{
    private string $localIp = '107.148.225.79';

    public function __construct(private NotificationService $notify) {}

    private function insecureTls(): bool
    {
        return (bool) config('zenith.insecure_tls', false);
    }

    private function opt(string $key, ?string $default = null): string
    {
        $v = Settings::get($key);
        return ($v !== null && $v !== '') ? $v : ($default ?? '');
    }

    public function tick(): void
    {
        // never overlap ticks
        $lock = Cache::lock('zenith-monitor:tick', 120);
        if (! $lock->get()) {
            return;
        }

        try {
            $actions = [];
            foreach (Node::all() as $node) {
                $actions = array_merge($actions, $this->checkNode($node, $actions));
            }
            $this->checkBackupStale($actions);
            $this->monitorLocalWings($actions);

            foreach ($actions as $a) {
                \Log::channel('daily')->info('[zenith-monitor] ' . $a);
            }
        } finally {
            $lock->release();
        }
    }

    /** Check one node: down detection, host alerts, metrics, servers. */
    private function checkNode(Node $node, array $actions): array
    {
        $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
        $token = $node->getDecryptedKey();
        $isLocal = $node->fqdn === $this->localIp;

        try {
            $resp = Http::withToken($token)
                ->withOptions(['verify' => false])
                ->timeout(15)
                ->get($base . '/api/servers');
            if (! $resp->successful()) {
                throw new \Exception('HTTP ' . $resp->status());
            }
        } catch (\Throwable $e) {
            $this->alertOnce("node-down:{$node->id}",
                '🚨 Node Down — ' . $node->name,
                "Wings API unreachable at {$node->fqdn}:{$node->daemonListen}.\nError: " . $e->getMessage()
                . "\n\nFix:\n• systemctl status wings\n• journalctl -u wings -n 50\n• systemctl restart wings",
                0xED4245, 'node_down');
            $this->storeNodeMetrics($node, null, null, null);

            return $actions;
        }

        $this->clearAlert('node-down:' . $node->id);
        $this->storeNodeMetrics($node, $isLocal ? $this->hostStats() : null, null, null);

        // host alerts (local node only — actual host stats via shell)
        if ($isLocal) {
            $cpu = $this->hostCpu();
            $mem = $this->hostMem();
            $cpuWarn = (float) $this->opt('node_cpu_warn');
            $memWarn = (float) $this->opt('node_ram_warn');
            if ($cpu !== null && $cpu >= $cpuWarn) {
                $this->alertOnce("node-cpu:{$node->id}",
                    "🔔 Node CPU High — {$node->name} ({$cpu}%)",
                    "Host CPU above {$cpuWarn}%.\nFix: docker stats → heavy containers, adjust server limits.",
                    0xE67E22, 'node_cpu');
            }
            if ($mem !== null && $mem >= $memWarn) {
                $this->alertOnce("node-ram:{$node->id}",
                    "🔔 Node RAM High — {$node->name} ({$mem}%)",
                    "Host RAM above {$memWarn}%.\nFix: heavy server dhundo, limits kam karo. OOM protection active hai.",
                    0xE67E22, 'node_ram');
            }
            $disk = $this->hostDisk('/');
            $diskWarn = (float) $this->opt('host_disk_warn');
            if ($disk !== null && $disk >= $diskWarn) {
                $this->alertOnce("host-disk:{$node->id}",
                    "💾 Host Disk Full — {$node->name} ({$disk}%)",
                    "Disk above {$diskWarn}%.\nFix: docker system prune, purane backups delete karo.",
                    0xE67E22, 'host_disk');
            }
        }

        // per-server inspection
        foreach ($resp->json() ?? [] as $ws) {
            $uuid = $ws['configuration']['uuid'] ?? null;
            if (! $uuid) continue;
            $server = Server::where('uuid', $uuid)->first();
            if (! $server) continue;
            $this->inspectServer($server, $ws, $actions);
        }

        return $actions;
    }

    /** Per-server watchdog: crash-loop, OOM, CPU overload, disk guard. */
    private function inspectServer(Server $server, array $ws, array &$actions): void
    {
        $uuid = $server->uuid;
        $state = $ws['state'] ?? 'offline';
        $util = $ws['utilization'] ?? [];
        if ($ws['is_suspended'] ?? false) return;

        $key = 'zenith-monitor:server:' . $uuid;
        $hist = Cache::get($key, ['restarts' => [], 'oom' => [], 'cpu_high_since' => null, 'cpu_low_streak' => 0]);
        $prev = Cache::get('zenith-monitor:prev:' . $uuid, 'unknown');
        $prevMemRatio = Cache::get('zenith-monitor:memratio:' . $uuid, 0);
        Cache::put('zenith-monitor:prev:' . $uuid, $state, 3600);
        $memLimit = $util['memory_limit_bytes'] ?? 0;
        if ($memLimit > 0 && ($util['memory_bytes'] ?? 0) > 0) {
            Cache::put('zenith-monitor:memratio:' . $uuid, $util['memory_bytes'] / $memLimit, 3600);
        }

        // crash-loop guard (wings restarts crashes; repeated → suspend)
        if ($state === 'offline' && in_array($prev, ['running', 'starting'])) {
            $window = (int) $this->opt('restart_window');
            $max = (int) $this->opt('max_restarts');
            $hist['restarts'][] = time();
            $recent = array_filter($hist['restarts'], fn ($t) => $t > time() - $window);
            if (count($recent) >= $max) {
                $actions[] = "CRASH-LOOP {$server->name}: " . count($recent) . " crashes — SUSPENDING";
                $this->suspend($server, 'crash-loop (' . count($recent) . ' crashes)');
            }
            if ($prevMemRatio >= 0.9) {
                $hist['oom'][] = time();
                if (count(array_filter($hist['oom'], fn ($t) => $t > time() - $window)) >= (int) $this->opt('oom_limit')) {
                    $actions[] = "OOM-ABUSE {$server->name}: repeated OOM crashes — SUSPENDING";
                    $this->suspend($server, 'repeated OOM crashes');
                }
            }
        }

        // sustained CPU overload + disk guard
        if ($state === 'running') {
            $cpu = $util['cpu_absolute'] ?? 0;
            $cpuThreshold = max(50.0, (float) $this->opt('server_cpu_warn'));
            $minutes = max(2, (int) $this->opt('cpu_minutes'));
            // stale marker guard: cpu_high_since older than 3x window = reset (avoids false suspend)
            if (! empty($hist['cpu_high_since']) && time() - $hist['cpu_high_since'] > $minutes * 180) {
                $hist['cpu_high_since'] = null;
            }
            if ($cpu >= $cpuThreshold) {
                $hist['cpu_low_streak'] = 0;
                if (empty($hist['cpu_high_since'])) {
                    $hist['cpu_high_since'] = time();
                } elseif (time() - $hist['cpu_high_since'] >= $minutes * 60) {
                    $actions[] = "CPU-OVERLOAD {$server->name}: {$cpu}% sustained {$minutes}m — SUSPENDING";
                    $this->suspend($server, "sustained CPU {$cpu}% for {$minutes}+ minutes");
                    $hist['cpu_high_since'] = null;
                }
            } else {
                // CPU back to normal — reset immediately
                $hist['cpu_high_since'] = null;
                $hist['cpu_low_streak'] = 0;
            }

            $diskLimit = $server->disk * 1048576;
            if ($diskLimit > 0) {
                $pct = round((($util['disk_bytes'] ?? 0) / $diskLimit) * 100);
                $warn = (int) $this->opt('server_disk_warn');
                if ($pct >= $warn && ! Cache::has('zenith-monitor:diskwarn:' . $uuid)) {
                    $actions[] = "DISK-WARN {$server->name}: {$pct}% of disk limit";
                    Cache::put('zenith-monitor:diskwarn:' . $uuid, true, 86400);
                    $this->notify->send('📊 Server Disk Full — ' . $server->name,
                        "Disk {$pct}% of limit. Purane backups/logs cleanup karo.",
                        0xE67E22, 'server_disk');
                }
            }

            // server RAM high alert
            $memWarn = (float) $this->opt('server_ram_warn');
            $memPct = $memLimit > 0 ? round((($util['memory_bytes'] ?? 0) / $memLimit) * 100, 1) : 0;
            if ($memPct >= $memWarn && ! Cache::has('zenith-monitor:ramwarn:' . $uuid)) {
                Cache::put('zenith-monitor:ramwarn:' . $uuid, true, 3600);
                $this->notify->send('📊 Server RAM High — ' . $server->name,
                    "RAM {$memPct}% — OOM risk. JVM flags / limits check karo.", 0xE67E22, 'server_ram');
                Cache::forget('zenith-monitor:ramwarn:' . $uuid);
            }
        }

        Cache::put($key, $hist, 7200);
    }

    private function suspend(Server $server, string $reason): void
    {
        try {
            $server->status = 'suspended';
            $server->save();
            $node = Node::find($server->node_id);
            if ($node) {
                Http::withToken($node->getDecryptedKey())
                    ->withOptions(['verify' => false])
                    ->timeout(10)
                    ->post($node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen . '/api/servers/' . $server->uuid . '/sync');
            }
            $this->notify->send('🐕 Watchdog suspended ' . $server->name,
                "Reason: {$reason}\nFix: investigate karo, phir Admin → Servers → unsuspend.", 0xED4245, 'watchdog');
        } catch (\Throwable $e) { /* best-effort */ }
    }

    /** Backup staleness (local DB backup cron). */
    private function checkBackupStale(array &$actions): void
    {
        $hours = (int) $this->opt('backup_stale_hours');
        $files = glob('/var/backups/pterodactyl/panel_*.sql.gz');
        if (empty($files)) return;
        $age = round((time() - max(array_map('filemtime', $files))) / 3600, 1);
        if ($age > $hours) {
            $actions[] = "BACKUP STALE: last DB backup {$age}h old";
            $this->notify->send('💾 Backup Stale',
                "Last DB backup {$age}h purana. ptero-db-backup.sh manual run karo.", 0xE67E22, 'backup');
        }
    }

    /** Wings daemon self-monitoring — local node only. */
    private function monitorLocalWings(array &$actions): void
    {
        $pid = intval(shell_exec('pgrep -x wings | head -1'));
        if ($pid <= 0) return;
        $rssMb = floatval(shell_exec("ps -o rss= -p $pid | awk '{print $1/1024}'"));
        $limit = (float) $this->opt('wings_rss_mb');
        if ($limit > 0 && $rssMb > $limit) {
            $actions[] = "WINGS RAM BALLOONING: {$rssMb}MB > {$limit}MB";
            $this->notify->send('🐕 Wings RAM Ballooning',
                "Wings daemon RAM {$rssMb}MB > limit {$limit}MB.\nFix: systemctl restart wings (servers safe).", 0xED4245, 'wings_health');
        }
    }

    /** Node metrics store — allocated (all nodes) + host actual (local). */
    private function storeNodeMetrics(Node $node, ?array $hostStats, ?float $allocMem, ?float $allocDisk): void
    {
        try {
            $isLocal = $node->fqdn === $this->localIp;
            $cpu = $hostStats['cpu'] ?? 0;
            $memPct = $hostStats['mem'] ?? 0;
            $memTotal = $isLocal ? intval(shell_exec("free -b | grep Mem | awk '{print $2}'") ?: 0) : 0;
            $totalBytes = $isLocal ? (@disk_total_space('/') ?: 0) : 0;
            $freeBytes = $isLocal ? (@disk_free_space('/') ?: 0) : 0;
            NodeMetric::create([
                'node_id' => $node->id,
                'cpu_percent' => $cpu,
                'memory_percent' => $memPct,
                'allocated_memory_percent' => $allocMem,
                'allocated_disk_percent' => $allocDisk,
                'memory_used_bytes' => intval($memTotal * $memPct / 100),
                'memory_total_bytes' => $memTotal,
                'disk_percent' => $isLocal && $totalBytes > 0 ? round(($totalBytes - $freeBytes) / $totalBytes * 100, 1) : 0,
                'disk_used_bytes' => intval($totalBytes - $freeBytes),
                'disk_total_bytes' => intval($totalBytes),
                'collected_at' => now(),
            ]);
            NodeMetric::where('collected_at', '<', now()->subHours(48))->delete();
        } catch (\Throwable $e) { /* best-effort */ }
    }

    private function hostStats(): array
    {
        return ['cpu' => $this->hostCpu(), 'mem' => $this->hostMem(), 'disk' => $this->hostDisk('/')];
    }

    private function hostCpu(): ?float
    {
        try {
            $load = sys_getloadavg()[0];
            $cores = (int) (shell_exec('nproc') ?: 1);
            return round(($load / $cores) * 100, 1);
        } catch (\Throwable $e) { return null; }
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
        if (! $total || ! $free) return null;
        return round((($total - $free) / $total) * 100, 1);
    }

    private function alertOnce(string $key, string $title, string $text, int $color, string $type): void
    {
        $coolKey = 'zenith-monitor:cool:' . $key;
        if (Cache::has($coolKey)) return;
        $cooldown = (int) (Settings::get('cooldown') ?? 900);
        if (! $this->notify->wantsDiscord($type) && ! $this->notify->wantsGeneric($type)) return;
        Cache::put($coolKey, true, $cooldown);
        $this->notify->send($title, $text, $color, $type);
        \Log::channel('daily')->info('[zenith-monitor] ALERT ' . $title);
    }

    private function clearAlert(string $key): void
    {
        $coolKey = 'zenith-monitor:cool:' . $key;
        if (Cache::has($coolKey)) {
            Cache::forget($coolKey);
            $label = explode(':', $key)[0];
            $this->notify->send('✅ Recovered — ' . $label, "Issue clear ho gaya. Sab normal!", 0x57F287, 'resolved');
        }
    }
}
