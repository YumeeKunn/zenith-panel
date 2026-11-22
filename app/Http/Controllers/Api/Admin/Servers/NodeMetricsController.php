<?php

namespace Pterodactyl\Http\Controllers\Api\Admin\Servers;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\NodeMetric;
use Pterodactyl\Models\ServerMetric;

/**
 * Zenith graph monitor API — node + server metrics for the dashboard.
 * GET /api/admin/nodes/{node}/metrics?hours=6
 * GET /api/admin/servers/{server}/metrics?hours=6  (existing)
 */
class NodeMetricsController
{
    public function __invoke(\Pterodactyl\Models\Node $node, \Illuminate\Http\Request $request): JsonResponse
    {
        $hours = min((int) $request->query('hours', '6'), 48);
        $limit = min((int) $request->query('limit', '600'), 5000);

        $metrics = NodeMetric::where('node_id', $node->id)
            ->where('collected_at', '>=', now()->subHours($hours))
            ->orderBy('collected_at')
            ->limit($limit)
            ->get();

        return new JsonResponse([
            'object' => 'node_metrics',
            'data' => $metrics->map(fn (NodeMetric $m) => [
                'collected_at' => $m->collected_at->toIso8601String(),
                'cpu_percent' => $m->cpu_percent,
                'memory_percent' => $m->memory_percent,
                'allocated_memory_percent' => $m->allocated_memory_percent,
                'allocated_disk_percent' => $m->allocated_disk_percent,
                'memory_used_gb' => round($m->memory_used_bytes / 1073741824, 2),
                'memory_total_gb' => round($m->memory_total_bytes / 1073741824, 2),
                'disk_percent' => $m->disk_percent,
                'disk_used_gb' => round($m->disk_used_bytes / 1073741824, 2),
                'disk_total_gb' => round($m->disk_total_bytes / 1073741824, 2),
            ])->all(),
            'meta' => ['node' => $node->name, 'hours' => $hours, 'count' => $metrics->count()],
        ]);
    }
}
