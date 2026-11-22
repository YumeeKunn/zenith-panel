<?php

namespace Minecraftplayermanager\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;

/**
 * MC Player Manager — console commands via wings (list, kick, op, ban, whitelist).
 * Uses the same command path the panel console uses (wings server command endpoint).
 */
class PlayerController
{
    /** GET /online — run "list" command, parse online players. */
    public function online(Server $server): JsonResponse
    {
        $output = $this->sendCommand($server, 'list');
        if ($output === null) return new JsonResponse(['error' => 'command failed'], 502);

        // parse "There are 2 of a max of 20 players online: Steve, Alex"
        preg_match('/There are (\d+) of a max of (\d+) players online[:]? ?(.*)/i', $output, $m);
        $players = [];
        if (isset($m[3])) {
            $players = array_filter(array_map('trim', explode(',', trim($m[3], " :\n\r"))));
        }
        return new JsonResponse([
            'online' => isset($m[1]) ? (int) $m[1] : 0,
            'max' => isset($m[2]) ? (int) $m[2] : 0,
            'players' => array_values($players),
            'raw' => $output,
        ]);
    }

    /** POST /command — send arbitrary whitelisted command (kick/op/ban/whitelist). */
    public function command(Server $server, Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:kick,op,deop,ban,unban,whitelist-add,whitelist-remove',
            'player' => 'required|string|max:32',
        ]);
        $cmdMap = [
            'kick' => 'kick {p} Kicked by admin',
            'op' => 'op {p}',
            'deop' => 'deop {p}',
            'ban' => 'ban {p} Banned by admin',
            'unban' => 'pardon {p}',
            'whitelist-add' => 'whitelist add {p}',
            'whitelist-remove' => 'whitelist remove {p}',
        ];
        $cmd = str_replace('{p}', $data['player'], $cmdMap[$data['action']]);
        $output = $this->sendCommand($server, $cmd);
        return new JsonResponse(['sent' => true, 'output' => $output ?? '']);
    }

    private function sendCommand(Server $server, string $command): ?string
    {
        try {
            $node = $server->node;
            $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
            $resp = Http::withToken($node->getDecryptedKey())
                ->withOptions(['verify' => !config('zenith.insecure_tls', false)])
                ->timeout(10)
                ->post($base . '/api/servers/' . $server->uuid . '/command', ['command' => $command]);
            return $resp->body() ?: 'sent';
        } catch (\Throwable $e) {
            return null;
        }
    }
}
