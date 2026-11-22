<?php

namespace Pterodactyl\Http\Controllers\Api\Admin\Servers;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Http\Controllers\Api\Application\ApplicationApiController;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerMetric;
use Pterodactyl\Transformers\Api\Admin\ServerMetricTransformer;

/**
 * Zenith historical metrics API (#4864) — admin API key se accessible.
 * GET /api/application/servers/{id}/metrics?hours=24
 */
class ServerMetricsController extends ApplicationApiController
{
    public function __invoke(Server $server, \Illuminate\Http\Request $request): JsonResponse
    {
        $hours = min((int) $request->query('hours', '24'), 168);

        // On-demand collection — graph tab open = collecting
        app(\Pterodactyl\Services\Zenith\MetricsService::class)->collect();
        $limit = min((int) $request->query('limit', '500'), 5000);

        $metrics = ServerMetric::where('server_id', $server->id)
            ->where('collected_at', '>=', now()->subHours($hours))
            ->orderBy('collected_at')
            ->limit($limit)
            ->get();

        return new JsonResponse([
            'object' => 'server_metrics',
            'data' => $metrics->map(fn (ServerMetric $m) => [
                'collected_at' => $m->collected_at->toIso8601String(),
                'cpu_percent' => $m->cpu_percent,
                'memory_bytes' => $m->memory_bytes,
                'memory_limit_bytes' => $m->memory_limit_bytes,
                'disk_bytes' => $m->disk_bytes,
                'network_rx_bytes' => $m->network_rx_bytes,
                'network_tx_bytes' => $m->network_tx_bytes,
                'state' => $m->state,
            ])->all(),
            'meta' => ['server' => $server->uuid, 'hours' => $hours, 'count' => $metrics->count()],
        ]);
    }
}
