<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zenith node-level metrics — host CPU/RAM/disk collected by watchdog.
 */
class NodeMetric extends Model
{
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    protected $table = 'node_metrics';

    protected $fillable = [
        'node_id', 'cpu_percent', 'memory_percent', 'allocated_memory_percent', 'allocated_disk_percent',
        'memory_used_bytes', 'memory_total_bytes', 'disk_percent', 'disk_used_bytes', 'disk_total_bytes',
        'collected_at',
    ];

    protected $casts = [
        'cpu_percent' => 'float',
        'memory_percent' => 'float',
        'allocated_memory_percent' => 'float',
        'allocated_disk_percent' => 'float',
        'memory_used_bytes' => 'integer',
        'memory_total_bytes' => 'integer',
        'disk_percent' => 'float',
        'disk_used_bytes' => 'integer',
        'disk_total_bytes' => 'integer',
        'collected_at' => 'datetime',
    ];

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
