<?php

namespace ZenithMonitor\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\NodeMetric;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerMetric;

/**
 * Zenith MetricsService — collects metrics ON DEMAND (graph monitor tab open
 * = collecting; tab closed = no collection, zero resource cost).
 *
 * Rate limited: max one collection per 3 seconds per node (matches the
 * dashboard auto-refresh interval).
 */
class MetricsService
{
    private const MIN_INTERVAL_SECONDS = 3;
    private const RETENTION_HOURS = 48;

    /** Collect fresh metrics for all nodes + servers (rate limited). */
    public function collect(): void
    {
        $key = 'zenith:metrics:collecting';
        if (Cache::has($key)) {
            return; // another request is collecting right now (3s window)
        }
        Cache::put($key, true, self::MIN_INTERVAL_SECONDS);

        try {
            foreach (Node::all() as $node) {
                $isLocal = $node->fqdn === '107.148.225.79';
                $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
                $token = $node->getDecryptedKey();

                // Node host metrics: actual (local) via shell, allocated (all) via DB
                $util = $node->utilization();
                $cpu = null;
                $memPct = null;
                $memTotal = 0;
                $diskPct = null;
                $totalBytes = 0;
                $freeBytes = 0;

                if ($isLocal) {
                    $load = sys_getloadavg()[0];
                    $cores = (int) (shell_exec('nproc') ?: 1);
                    $cpu = round(($load / $cores) * 100, 1);
                    $free = shell_exec("free | grep Mem | awk '{print $3/$2 * 100.0}'");
                    $memPct = $free !== null ? round((float) trim($free), 1) : null;
                    $memTotal = intval(shell_exec("free -b | grep Mem | awk '{print $2}'") ?: 0);
                    $totalBytes = @disk_total_space('/') ?: 0;
                    $freeBytes = @disk_free_space('/') ?: 0;
                    $diskPct = $totalBytes > 0 ? round(($totalBytes - $freeBytes) / $totalBytes * 100, 1) : null;
                }

                NodeMetric::create([
                    'node_id' => $node->id,
                    'cpu_percent' => $cpu ?? 0,
                    'memory_percent' => $memPct ?? 0,
                    'allocated_memory_percent' => $util['memory']['percent'],
                    'allocated_disk_percent' => $util['disk']['percent'],
                    'memory_used_bytes' => intval($memTotal * ($memPct ?? 0) / 100),
                    'memory_total_bytes' => $memTotal,
                    'disk_percent' => $diskPct ?? ($util['disk']['percent'] ?? 0),
                    'disk_used_bytes' => intval($totalBytes - $freeBytes),
                    'disk_total_bytes' => intval($totalBytes),
                    'collected_at' => now(),
                ]);

                // Server container metrics via wings API
                try {
                    $resp = Http::withToken($token)->withOptions(['verify' => !config('zenith.insecure_tls', false)])
                        ->timeout(15)->get($base . '/api/servers');
                    if (!$resp->successful()) continue;
                    foreach ($resp->json() ?? [] as $ws) {
                        $uuid = $ws['configuration']['uuid'] ?? null;
                        if (!$uuid) continue;
                        $server = Server::where('uuid', $uuid)->first();
                        if (!$server) continue;
                        $u = $ws['utilization'] ?? [];
                        ServerMetric::create([
                            'server_id' => $server->id,
                            'cpu_percent' => (float) ($u['cpu_absolute'] ?? 0),
                            'memory_bytes' => (int) ($u['memory_bytes'] ?? 0),
                            'memory_limit_bytes' => (int) ($u['memory_limit_bytes'] ?? 0),
                            'disk_bytes' => (int) ($u['disk_bytes'] ?? 0),
                            'network_rx_bytes' => (int) ($u['network']['rx_bytes'] ?? 0),
                            'network_tx_bytes' => (int) ($u['network']['tx_bytes'] ?? 0),
                            'state' => $ws['state'] ?? 'offline',
                            'collected_at' => now(),
                        ]);
                    }
                } catch (\Throwable $e) { /* skip server metrics */ }
            }

            // retention: 48h
            NodeMetric::where('collected_at', '<', now()->subHours(self::RETENTION_HOURS))->delete();
            ServerMetric::where('collected_at', '<', now()->subHours(self::RETENTION_HOURS))->delete();
        } finally {
            Cache::forget($key);
        }
    }
}
