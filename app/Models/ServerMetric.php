<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zenith historical metrics — collected every watchdog cycle (1 min).
 * Powers zenith:metrics:report + API endpoints. UI baad me.
 *
 * @property int $id
 * @property int $server_id
 * @property float $cpu_percent
 * @property int $memory_bytes
 * @property int $memory_limit_bytes
 * @property int $disk_bytes
 * @property int $network_rx_bytes
 * @property int $network_tx_bytes
 * @property string $state
 * @property \Carbon\Carbon $collected_at
 */
class ServerMetric extends Model
{
    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    protected $table = 'server_metrics';

    protected $fillable = [
        'server_id', 'cpu_percent', 'memory_bytes', 'memory_limit_bytes',
        'disk_bytes', 'network_rx_bytes', 'network_tx_bytes', 'state', 'collected_at',
    ];

    protected $casts = [
        'cpu_percent' => 'float',
        'memory_bytes' => 'integer',
        'memory_limit_bytes' => 'integer',
        'disk_bytes' => 'integer',
        'network_rx_bytes' => 'integer',
        'network_tx_bytes' => 'integer',
        'collected_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
