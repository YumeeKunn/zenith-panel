<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerMetric;

/**
 * Zenith: historical metrics report (#4864) — data watchdog har minute
 * collect karta hai (server_metrics table). Ye command uska readable
 * summary deta hai. API endpoint bhi available: /api/admin/servers/{id}/metrics
 */
class MetricsReportCommand extends Command
{
    protected $signature = 'zenith:metrics
        {server? : Server ID or UUID (optional — all servers by default)}
        {--hours=24 : How many hours of history to summarize}
        {--points=12 : How many sample points to show}';

    protected $description = 'Zenith metrics: historical CPU/RAM/disk summary per server';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $points = (int) $this->option('points');
        $since = now()->subHours($hours);

        $servers = Server::query()
            ->when($this->argument('server'), fn ($q, $s) => $q->where('id', $s)->orWhere('uuid', $s))
            ->get();

        if ($servers->isEmpty()) {
            $this->warn('No servers found.');
            return 0;
        }

        foreach ($servers as $server) {
            $rows = ServerMetric::where('server_id', $server->id)
                ->where('collected_at', '>=', $since)
                ->orderBy('collected_at')
                ->get();

            $this->info("=== {$server->name} ({$server->uuid}) — {$hours}h history, {$rows->count()} points ===");

            if ($rows->isEmpty()) {
                $this->line('  (no metrics collected yet)');
                continue;
            }

            $cpuAvg = round($rows->avg('cpu_percent'), 1);
            $cpuMax = round($rows->max('cpu_percent'), 1);
            $memAvg = round($rows->avg('memory_bytes') / 1048576);
            $memMax = round($rows->max('memory_bytes') / 1048576);
            $disk = round(($rows->last()->disk_bytes ?? 0) / 1048576);
            $memLimit = round(($rows->last()->memory_limit_bytes ?? 0) / 1048576);

            $this->line("  CPU avg: {$cpuAvg}% | max: {$cpuMax}%");
            $this->line("  RAM avg: {$memAvg}MB | max: {$memMax}MB | limit: {$memLimit}MB");
            $this->line("  Disk now: {$disk}MB");

            // time-series sample points
            $step = max(1, intdiv($rows->count(), $points));
            $this->line('  timeline:');
            for ($i = 0; $i < $rows->count(); $i += $step) {
                $r = $rows[$i];
                $t = $r->collected_at->format('H:i');
                $c = round($r->cpu_percent, 1);
                $m = round($r->memory_bytes / 1048576);
                $this->line("    {$t}  cpu {$c}%  ram {$m}MB  [{$r->state}]");
            }
            $this->line('');
        }

        return 0;
    }
}
