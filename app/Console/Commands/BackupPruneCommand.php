<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Daemon\ServerClient;

/**
 * Zenith: server backup rotation (#5380 discussion — per-schedule backup
 * rotation missing upstream). Har server ke unlocked backups me se N
 * newest rakhta hai, baaki (wings file + DB row) delete karta hai.
 *
 *   php artisan zenith:backups:prune --keep=5
 *   php artisan zenith:backups:prune --keep=3 --older-than-days=14
 */
class BackupPruneCommand extends Command
{
    protected $signature = 'zenith:backups:prune
        {--keep=5 : Per server kitne newest backups rakhne hain}
        {--older-than-days= : Sirf itne din se purane backups delete karo}
        {--server= : Specific server id/uuid (optional)}
        {--dry-run : Report only, delete nothing}';

    protected $description = 'Zenith backup rotation: keep N newest unlocked backups per server';

    public function handle(): int
    {
        $keep = max(1, (int) $this->option('keep'));
        $olderDays = $this->option('older-than-days') !== null ? (int) $this->option('older-than-days') : null;
        $dry = (bool) $this->option('dry-run');

        $servers = Server::query()
            ->when($this->option('server'), fn ($q, $s) => $q->where('id', $s)->orWhere('uuid', $s))
            ->get();

        $deleted = 0;
        foreach ($servers as $server) {
            $query = Backup::where('server_id', $server->id)
                ->where('is_successful', 1)
                ->where('is_locked', 0)  // locked backups kabhi nahi chhoote
                ->orderBy('created_at', 'desc');

            if ($olderDays !== null) {
                $query->where('created_at', '<', now()->subDays($olderDays));
            }

            $all = $query->get();
            $toDelete = $all->slice($keep)->values(); // newest N keep

            if ($toDelete->isEmpty()) {
                $this->line("{$server->name}: {$all->count()} backups — nothing to prune");
                continue;
            }

            $this->line("{$server->name}: {$all->count()} backups, deleting {$toDelete->count()} (keep {$keep})");

            $node = Node::find($server->node_id);
            $base = $node ? $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen : null;
            $token = $node ? $node->getDecryptedKey() : null;

            foreach ($toDelete as $backup) {
                if ($dry) {
                    $this->line("  [dry] would delete: {$backup->name} ({$backup->uuid})");
                    continue;
                }
                try {
                    if ($base && $token) {
                        Http::withToken($token)->withOptions(['verify' => !config('zenith.insecure_tls', false)])
                            ->timeout(30)->delete($base . '/api/servers/' . $server->uuid . '/backups/' . $backup->uuid);
                    }
                    $backup->delete();
                    $deleted++;
                    $this->line("  deleted: {$backup->name}");
                } catch (\Throwable $e) {
                    $this->error("  failed {$backup->name}: " . $e->getMessage());
                }
            }
        }

        $this->info($dry ? 'Dry run — nothing deleted.' : "Done. {$deleted} backups deleted.");
        return 0;
    }
}
