<?php

namespace ZenithMonitor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\NodeMetric;
use Pterodactyl\Models\ServerMetric;
use ZenithMonitor\Services\MetricsService;

class GraphController
{
    /** GET /nodes/{node:id}/graphs?hours=6 — collect-on-read + return series. */
    public function node(Node $node, Request $request): JsonResponse
    {
        $hours = min((int) $request->query('hours', '6'), 48);
        app(MetricsService::class)->collect();

        $metrics = NodeMetric::where('node_id', $node->id)
            ->where('collected_at', '>=', now()->subHours($hours))
            ->orderBy('collected_at')
            ->get();

        return new JsonResponse(['data' => $metrics->map(fn (NodeMetric $m) => [
            'collected_at' => $m->collected_at->toIso8601String(),
            'cpu_percent' => $m->cpu_percent,
            'memory_percent' => $m->memory_percent,
            'allocated_memory_percent' => $m->allocated_memory_percent,
            'allocated_disk_percent' => $m->allocated_disk_percent,
        ])->all(), 'meta' => ['node' => $node->name, 'hours' => $hours]]);
    }

    /** GET /servers/{server:admin_identifier}/graphs?hours=6 */
    public function server(\Pterodactyl\Models\Server $server, Request $request): JsonResponse
    {
        $hours = min((int) $request->query('hours', '6'), 48);
        app(MetricsService::class)->collect();

        $metrics = ServerMetric::where('server_id', $server->id)
            ->where('collected_at', '>=', now()->subHours($hours))
            ->orderBy('collected_at')
            ->get();

        return new JsonResponse(['data' => $metrics->map(fn (ServerMetric $m) => [
            'collected_at' => $m->collected_at->toIso8601String(),
            'cpu_percent' => $m->cpu_percent,
            'memory_mb' => round($m->memory_bytes / 1048576),
            'state' => $m->state,
        ])->all(), 'meta' => ['server' => $server->name, 'hours' => $hours]]);
    }

    /** GET /nodes/{node:id}/server-usage — live per-server usage snapshot. */
    public function nodeUsage(Node $node, Request $request): JsonResponse
    {
        $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
        $token = $node->getDecryptedKey();

        try {
            $resp = Http::withToken($token)
                ->withOptions(['verify' => false])
                ->timeout(15)
                ->get($base . '/api/servers');
            if (!$resp->successful()) {
                return new JsonResponse(['error' => 'wings HTTP ' . $resp->status()], 502);
            }
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }

        $servers = [];
        foreach ($resp->json() ?? [] as $ws) {
            $uuid = $ws['configuration']['uuid'] ?? null;
            if (!$uuid) continue;
            $server = \Pterodactyl\Models\Server::where('uuid', $uuid)->first();
            if (!$server) continue;
            $util = $ws['utilization'] ?? [];
            $servers[] = [
                'id' => $server->id,
                'name' => $server->name,
                'uuid_short' => substr($uuid, 0, 8),
                'state' => $ws['state'] ?? 'offline',
                'suspended' => $ws['is_suspended'] ?? false,
                'cpu_percent' => round($util['cpu_absolute'] ?? 0, 1),
                'cpu_limit' => $server->cpu,
                'memory_used_mb' => round(($util['memory_bytes'] ?? 0) / 1048576),
                'memory_limit_mb' => $server->memory,
                'memory_percent' => $server->memory > 0 ? round((($util['memory_bytes'] ?? 0) / ($server->memory * 1048576)) * 100, 1) : 0,
                'disk_used_mb' => round(($util['disk_bytes'] ?? 0) / 1048576),
                'disk_limit_mb' => $server->disk,
                'disk_percent' => $server->disk > 0 ? round((($util['disk_bytes'] ?? 0) / ($server->disk * 1048576)) * 100, 1) : 0,
            ];
        }

        return new JsonResponse(['data' => $servers, 'meta' => ['node' => $node->name, 'count' => count($servers)]]);
    }
}
