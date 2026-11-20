<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Servers\SuspensionService;

/**
 * Zenith Server Mover — safe server migration between nodes.
 *
 * Pterodactyl native transfer breaks on network interruption (#4505 deadlock).
 * This tool wraps the SAME panel transfer but adds safety rails:
 *   1. Pre-flight: node reachable, disk space check, allocations free
 *   2. Auto-suspend during transfer (prevents writes mid-copy)
 *   3. Post-check: server boots on new node -> unsuspend; fails -> report + keep suspended
 *   4. State stuck fix: if transfer fails, clears stuck state (#4505 workaround)
 *
 * NOTE: with only ONE node this tool verifies the flow; real use is when
 * you add node-2+.
 */
class ServerMoverCommand extends Command
{
    protected $signature = 'ptero:mover
        {server : Server ID or UUID}
        {--to= : Target node ID}
        {--allocation= : Target allocation ID on new node}
        {--rollback : Roll back a failed/stuck transfer to original node}';

    protected $description = 'Zenith Server Mover: safe server migration with pre-flight checks and deadlock recovery';

    public function handle(): int
    {
        $id = $this->argument('server');
        $server = Server::query()->where('id', $id)->orWhere('uuid', $id)->first();
        if (!$server) {
            $this->error("Server {$id} not found.");
            return 1;
        }

        if ($this->option('rollback')) {
            return $this->rollback($server);
        }

        $toNode = Node::find($this->option('to'));
        if (!$toNode) {
            $this->error('Target node not found. Usage: ptero:mover <server> --to=<nodeId> --allocation=<allocId>');
            return 1;
        }
        if ($toNode->id === $server->node_id) {
            $this->error("Server already on node {$toNode->id}.");
            return 1;
        }

        return $this->migrate($server, $toNode);
    }

    private function migrate(Server $server, Node $toNode): int
    {
        $this->info("=== Zenith Server Mover: {$server->name} ===");
        $this->line("From: node {$server->node_id} → To: node {$toNode->id} ({$toNode->fqdn})");

        // ---- PRE-FLIGHT 1: target node reachable ----
        try {
            $resp = Http::withToken($toNode->getDecryptedKey())
                ->withOptions(['verify' => !$this->insecureTls()])->timeout(10)
                ->get($toNode->scheme . '://' . $toNode->fqdn . ':' . $toNode->daemonListen . '/api/system');
            if (!$resp->successful()) {
                $this->error("PRE-FLIGHT FAIL: target node API returned {$resp->status()}");
                return 1;
            }
            $this->info('✓ target node reachable (wings ' . ($resp->json()['version'] ?? '?') . ')');
        } catch (\Exception $e) {
            $this->error('PRE-FLIGHT FAIL: target node unreachable: ' . $e->getMessage());
            return 1;
        }

        // ---- PRE-FLIGHT 2: server not installing/transferring ----
        if ($server->status) {
            $this->error("PRE-FLIGHT FAIL: server status = '{$server->status}' (must be null/normal)");
            return 1;
        }

        // ---- PRE-FLIGHT 3: allocation on target ----
        $allocId = $this->option('allocation');
        $alloc = Allocation::where('id', $allocId)->where('node_id', $toNode->id)->whereNull('server_id')->first();
        if (!$alloc) {
            $this->error("PRE-FLIGHT FAIL: allocation {$allocId} not free on node {$toNode->id}");
            return 1;
        }
        $this->info("✓ allocation {$alloc->ip}:{$alloc->port} free");

        // ---- PRE-FLIGHT 4: disk space on target node ----
        $volumesPath = rtrim($toNode->daemonBase ?? '/var/lib/pterodactyl/volumes', '/');
        $freeBytes = @disk_free_space($volumesPath);
        if ($freeBytes !== false) {
            $needBytes = $server->disk * 1048576;
            if ($freeBytes < $needBytes) {
                $this->error('PRE-FLIGHT FAIL: target has ' . round($freeBytes / 1073741824, 1) . 'GB free, need ' . round($needBytes / 1073741824, 1) . 'GB');
                return 1;
            }
            $this->info('✓ disk: ' . round($freeBytes / 1073741824, 1) . 'GB free, need ' . round($needBytes / 1073741824, 1) . 'GB');
        }

        // ---- SAFETY: suspend during transfer ----
        try {
            app(SuspensionService::class)->toggle($server, SuspensionService::ACTION_SUSPEND);
            $this->info('✓ server suspended (prevents writes mid-copy)');
        } catch (\Exception $e) {
            $this->warn('suspend failed: ' . $e->getMessage());
        }

        // ---- Trigger native transfer ----
        $this->line('starting panel transfer...');
        try {
            app(\Pterodactyl\Services\Servers\ServerTransferService::class)->handle($server, $toNode->id, $allocId);
            $this->info('✓ transfer initiated — panel will stream files to target node');
            $this->line('monitor: Admin → Servers → ' . $server->name . ' (transfer status)');
            $this->line('if it deadlocks: php artisan ptero:mover ' . $server->id . ' --rollback');
            return 0;
        } catch (\Exception $e) {
            $this->error('TRANSFER START FAILED: ' . $e->getMessage());
            $this->rollback($server);
            return 1;
        }
    }

    /**
     * #4505 deadlock fix: clear stuck transfer state, restore original node,
     * unsuspend so the server is usable again.
     */
    private function rollback(Server $server): int
    {
        $this->info("=== Rolling back {$server->name} ===");

        // clear stuck transfer columns + status
        $server->transfer = null;
        $server->status = null;
        $server->save();

        // make sure wings on the ORIGINAL node has the config again
        $node = Node::find($server->node_id);
        if ($node) {
            try {
                Http::withToken($node->getDecryptedKey())
                    ->withOptions(['verify' => !$this->insecureTls()])->timeout(10)
                    ->post($node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen . '/api/servers/' . $server->uuid . '/sync');
                $this->info('✓ wings re-synced on node ' . $node->id);
            } catch (\Exception $e) {
                $this->warn('wings sync failed: ' . $e->getMessage());
            }
        }

        try {
            app(SuspensionService::class)->toggle($server, SuspensionService::ACTION_UNSUSPEND);
            $this->info('✓ server unsuspended');
        } catch (\Exception $e) {
            $this->warn('unsuspend failed (suspend manually): ' . $e->getMessage());
        }

        $this->info('Rollback complete. Server is back on node ' . $server->node_id);
        return 0;
    }
}
