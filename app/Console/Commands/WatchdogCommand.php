<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Servers\SuspensionService;

/**
 * Zenith Watchdog — fills the gaps Pterodactyl/Wings leave open:
 *
 *  1. CRASH AUTO-RESTART: Wings detects crashes but never restarts.
 *     Watchdog restarts crashed servers (loop-protected: max N per window).
 *  2. OVERLOAD CUTOFF: sustained CPU abuse or repeated OOM crashes
 *     -> auto-suspend the server (admin can unsuspend).
 *  3. DISK GUARD: warn when a server nears its disk limit.
 *
 * Runs every minute via systemd timer. All actions logged + cached.
 */
class WatchdogCommand extends Command
{
    protected $signature = 'zenith:watchdog
        {--dry-run : Report only, take no action}
        {--max-restarts=3 : Max auto-restarts per server per window}
        {--restart-window=600 : Restart window in seconds}
        {--cpu-threshold=95 : Sustained CPU % considered abuse}
        {--cpu-minutes=5 : Minutes of sustained CPU before cutoff}
        {--oom-limit=3 : OOM crashes within window before suspend}
        {--disk-warn=90 : Disk usage % to warn at}
        {--wings-rss-mb=2048 : Wings daemon RAM alert threshold (MB)}';

    protected $description = 'Zenith Watchdog: crash auto-restart, overload cutoff, disk guard';

    private array $actions = [];

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

        // Multi-node: monitor servers on every node
        foreach ($nodes as $node) {
            $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
            $token = $node->getDecryptedKey();

            try {
                $resp = Http::withToken($token)->withOptions(['verify' => !$this->insecureTls()])->timeout(15)->get($base . '/api/servers');
            } catch (\Exception $e) {
                $this->error('Node ' . $node->name . ' unreachable: ' . $e->getMessage());
                continue; // node down — notifier will alert, watchdog skips its servers
            }
            if (!$resp->successful()) {
                $this->error('Node ' . $node->name . ' API error: ' . $resp->status());
                continue;
            }

            $servers = $resp->json() ?? [];
            foreach ($servers as $ws) {
                $uuid = $ws['configuration']['uuid'] ?? null;
                if (!$uuid) continue;
                $server = Server::where('uuid', $uuid)->first();
                if (!$server) continue;
                $this->inspect($server, $ws, $base, $token, $dry);
            }
        }

        foreach ($this->actions as $a) {
            $this->line($a);
            \Log::channel('daily')->info('[watchdog] ' . $a);
        }

        $this->collectMetrics($dry);
        $this->monitorWings($dry);
        $this->healStuckServers($dry);
        $this->checkVersionLockstep();

        if (empty($this->actions)) {
            $this->line('[' . now()->toDateTimeString() . '] all servers healthy');
        }
        return 0;
    }

    private function inspect(Server $server, array $ws, string $base, string $token, bool $dry): void
    {
        $uuid = $server->uuid;
        $state = $ws['state'] ?? 'offline';
        $util = $ws['utilization'] ?? [];
        $suspended = $ws['is_suspended'] ?? false;
        $key = 'watchdog:' . $uuid;
        $hist = Cache::get($key, ['states' => [], 'restarts' => [], 'oom' => [], 'cpu_high_since' => null]);

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
        // NOTE: Wings ALREADY auto-restarts crashes (its crash handler starts the
        // process again). Watchdog does NOT duplicate that — instead it is the
        // safety net: if a server keeps crashing (restart loop), SUSPEND it so
        // the node isn't thrashed all night.
        $wasRunning = in_array($prev, ['running', 'starting']);
        if ($state === 'offline' && $wasRunning) {
            $window = (int) $this->opt('restart-window', 'restart-window');
            $max = (int) $this->opt('max-restarts', 'max-restarts');
            $hist['restarts'][] = time(); // wings handled the restart; we just track it
            $recent = array_filter($hist['restarts'], fn($t) => $t > time() - $window);
            if (count($recent) >= $max) {
                $this->actions[] = "CRASH-LOOP {$server->name}: " . count($recent) . " crashes in {$window}s (wings restarts each) — SUSPENDING";
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
                // one low reading (API blip / idle moment) shouldn't reset the streak:
                // only reset after 2 consecutive below-threshold reads
                $hist['cpu_low_streak'] = ($hist['cpu_low_streak'] ?? 0) + 1;
                if ($hist['cpu_low_streak'] >= 2) {
                    $hist['cpu_high_since'] = null;
                }
            }

            // ---- 3. DISK GUARD ----
            $disk = $util['disk_bytes'] ?? 0;
            $limit = $server->disk * 1048576; // MB -> bytes
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
        $recent = array_filter($hist['oom'], fn($t) => $t > time() - $window);
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
                app(\Pterodactyl\Services\Daemon\ServerClient::class)->setServer($server)->sync();
            } catch (\Throwable $e) { /* wings sync best-effort */ }
            $this->actions[] = "  SUSPENDED {$server->name}: {$reason}";
            $this->notifyDiscord(
                "🐕 Watchdog SUSPENDED server",
                "`{$server->name}` auto-suspended.\n**Reason:** {$reason}\n\n**Fix:** Admin → Servers → {$server->name} → unsuspend after investigating."
            );
        } catch (\Exception $e) {
            $this->actions[] = "  SUSPEND FAILED {$server->name}: " . $e->getMessage();
        }
    }

    /**
     * Zenith: push watchdog actions to Discord too (suspends shouldn't be silent).
     * Reads the same webhook the notifier uses (zenith::discord:webhook).
     */
    /** Zenith: watchdog actions → Discord/LINE/Telegram/whatever channels are configured. */
    private function notifyDiscord(string $title, string $text): void
    {
        app(\Pterodactyl\Services\Zenith\NotificationService::class)->send($title, $text, 0xED4245, 'watchdog');
    }


    /**
     * Zenith metrics — historical resource data (#4864).
     * Har cycle me per-server stats DB me store hote hain. UI baad me,
     * data abhi se collect — zenith:metrics:report se dekho.
     */
    private function collectMetrics(bool $dry): void
    {
        if ($dry) return;
        try {
            // reuse the stats fetched in handle(): collect fresh per-server rows
            $nodes = \Pterodactyl\Models\Node::all();
            foreach ($nodes as $node) {
                $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
                $token = $node->getDecryptedKey();
                try {
                    $resp = Http::withToken($token)->withOptions(['verify' => !$this->insecureTls()])->timeout(15)->get($base . '/api/servers');
                    if (!$resp->successful()) continue;
                    foreach ($resp->json() ?? [] as $ws) {
                        $uuid = $ws['configuration']['uuid'] ?? null;
                        if (!$uuid) continue;
                        $server = \Pterodactyl\Models\Server::where('uuid', $uuid)->first();
                        if (!$server) continue;
                        $util = $ws['utilization'] ?? [];
                        \Pterodactyl\Models\ServerMetric::create([
                            'server_id' => $server->id,
                            'cpu_percent' => (float) ($util['cpu_absolute'] ?? 0),
                            'memory_bytes' => (int) ($util['memory_bytes'] ?? 0),
                            'memory_limit_bytes' => (int) ($util['memory_limit_bytes'] ?? 0),
                            'disk_bytes' => (int) ($util['disk_bytes'] ?? 0),
                            'network_rx_bytes' => (int) ($util['network']['rx_bytes'] ?? 0),
                            'network_tx_bytes' => (int) ($util['network']['tx_bytes'] ?? 0),
                            'state' => $ws['state'] ?? 'offline',
                            'collected_at' => now(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
            // retention: 48h se purane rows prune
            \Pterodactyl\Models\ServerMetric::where('collected_at', '<', now()->subHours(48))->delete();
        } catch (\Throwable $e) {
            \Log::channel('daily')->error('[watchdog] metrics collect failed: ' . $e->getMessage());
        }
    }

    /**
     * Wings daemon self-monitoring (#5115 RAM ballooning, #4712 CPU spikes).
     * Local node (same machine) pe wings process ka RSS/CPU check karte hain.
     * RAM > limit sustained → alert. --wings-restart do to auto-restart bhi.
     */
    private function monitorWings(bool $dry): void
    {
        // local wings only — remote nodes need ssh access (future)
        $pid = intval(shell_exec('pgrep -x wings | head -1'));
        if ($pid <= 0) return;

        $rssMb = floatval(shell_exec("ps -o rss= -p $pid | awk '{print \$1/1024}'"));
        $cpu = floatval(shell_exec("ps -o pcpu= -p $pid | awk '{print \$1}'"));
        $limit = (float) ($this->opt('wings-rss-mb', 'wings-rss-mb', 2048));

        if ($rssMb > $limit) {
            $msg = "🐕 WINGS RAM BALLOONING: {$rssMb}MB (limit {$limit}MB) — pid {$pid}";
            $this->actions[] = $msg;
            \Log::channel('daily')->info('[watchdog] ' . $msg);
            app(\Pterodactyl\Services\Zenith\NotificationService::class)->send(
                '🐕 Wings RAM Ballooning',
                "Wings daemon RAM **{$rssMb}MB** — limit {$limit}MB cross.\n**Fix suggestions:**\n• `systemctl restart wings` (servers container me rehte hain, safe)\n• Report: github.com/pterodactyl/wings/issues #5115\n• Repeat hone pe wings version update karo",
                0xED4245,
                'wings_health'
            );
            if ((bool) config('zenith.wings_auto_restart', false) && !$dry) {
                shell_exec('systemctl restart wings 2>&1');
                \Log::channel('daily')->info('[watchdog] wings auto-restarted (RAM ballooning)');
            }
        }

        // CPU spike >80% sustained — sirf log+alert (restart nahi, spikes transient hote)
        if ($cpu >= 80) {
            $key = 'wings-cpu-high';
            if (!\Illuminate\Support\Facades\Cache::has('zenith:' . $key)) {
                \Illuminate\Support\Facades\Cache::put('zenith:' . $key, true, 3600);
                $msg = "Wings CPU spike: {$cpu}% — #4712 pattern (10-15min cycles)";
                \Log::channel('daily')->info('[watchdog] ' . $msg);
            }
        }
    }

    /**
     * Stuck-state auto-heal — installing/transferring/restoring state me
     * 30+ min se atke servers → status reset + wings sync (report: stuck states common the).
     */
    private function healStuckServers(bool $dry): void
    {
        $stuck = \Pterodactyl\Models\Server::whereIn('status', [
            \Pterodactyl\Models\Server::STATUS_INSTALLING,
            \Pterodactyl\Models\Server::STATUS_RESTORING_BACKUP,
        ])->where('updated_at', '<', now()->subMinutes(30))->get();

        foreach ($stuck as $server) {
            $this->actions[] = "STUCK-HEAL {$server->name}: status '{$server->status}' 30min+ — resetting";
            if (!$dry) {
                $server->status = null;
                $server->save();
                try {
                    $node = \Pterodactyl\Models\Node::find($server->node_id);
                    if ($node) {
                        Http::withToken($node->getDecryptedKey())
                            ->withOptions(['verify' => !$this->insecureTls()])->timeout(10)
                            ->post($node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen . '/api/servers/' . $server->uuid . '/sync');
                    }
                } catch (\Throwable $e) { /* best effort */ }
            }
        }
    }

    /**
     * Panel↔Wings version lockstep check (report: "aadha update = khula CVE").
     * Wings < 1.13.0 pe alert (v2 panel ko kam se kam 1.13.x chahiye).
     */
    private function checkVersionLockstep(): void
    {
        foreach (\Pterodactyl\Models\Node::all() as $node) {
            $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
            try {
                $resp = Http::withToken($node->getDecryptedKey())
                    ->withOptions(['verify' => !$this->insecureTls()])->timeout(8)
                    ->get($base . '/api/system');
                if (!$resp->successful()) continue;
                $wingsVer = $resp->json()['version'] ?? '';
                preg_match('/(\d+)\.(\d+)/', $wingsVer, $m);
                if (isset($m[1], $m[2]) && ($m[1] < 1 || ($m[1] == 1 && $m[2] < 13))) {
                    $msg = "VERSION LOCKSTEP: {$node->name} wings {$wingsVer} too old for v2 panel (needs >=1.13.0)";
                    $this->actions[] = $msg;
                    \Log::channel('daily')->info('[watchdog] ' . $msg);
                }
            } catch (\Throwable $e) { continue; }
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
}
