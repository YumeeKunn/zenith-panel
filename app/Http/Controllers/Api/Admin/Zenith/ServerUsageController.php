<?php

namespace Pterodactyl\Http\Controllers\Api\Admin\Zenith;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/**
 * Zenith: live per-server resource usage for a node — collected ON READ
 * (admin opens the tab = collecting; tab closed = zero cost).
 * Result cached 3s to match the dashboard refresh interval.
 */
class ServerUsageController
{
    public function __invoke(Node $node, \Illuminate\Http\Request $request): JsonResponse
    {
        $cacheKey = 'zenith:usage:' . $node->id;

        // 3s result cache — rapid polls reuse the same snapshot
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return new JsonResponse(['object' => 'server_usage', 'data' => $cached, 'meta' => [
                'node' => $node->name, 'node_id' => $node->id, 'cached' => true,
            ]]);
        }

        $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
        $token = $node->getDecryptedKey();

        try {
            $resp = Http::withToken($token)
                ->withOptions(['verify' => !config('zenith.insecure_tls', false)])
                ->timeout(15)
                ->get($base . '/api/servers');
            if (!$resp->successful()) {
                return new JsonResponse([
                    'object' => 'server_usage',
                    'data' => [],
                    'meta' => ['node' => $node->name, 'node_id' => $node->id, 'error' => 'wings HTTP ' . $resp->status()],
                ], 502);
            }

            $servers = [];
            foreach ($resp->json() ?? [] as $ws) {
                $uuid = $ws['configuration']['uuid'] ?? null;
                if (!$uuid) continue;
                $server = Server::where('uuid', $uuid)->first();
                if (!$server) continue;
                $util = $ws['utilization'] ?? [];
                $memLimit = (int) ($util['memory_limit_bytes'] ?? 0);
                $diskLimit = $server->disk * 1048576;

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
                    'memory_percent' => $memLimit > 0 ? round((($util['memory_bytes'] ?? 0) / $memLimit) * 100, 1) : 0,
                    'disk_used_mb' => round(($util['disk_bytes'] ?? 0) / 1048576),
                    'disk_limit_mb' => $server->disk,
                    'disk_percent' => $server->disk > 0 ? round((($util['disk_bytes'] ?? 0) / $diskLimit) * 100, 1) : 0,
                ];
            }

            Cache::put($cacheKey, $servers, 3);

            return new JsonResponse([
                'object' => 'server_usage',
                'data' => $servers,
                'meta' => ['node' => $node->name, 'node_id' => $node->id, 'cached' => false, 'count' => count($servers)],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'object' => 'server_usage',
                'data' => [],
                'meta' => ['node' => $node->name, 'node_id' => $node->id, 'error' => $e->getMessage()],
            ], 502);
        }
    }
}
