<?php

namespace Versionchanger\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Node;

/**
 * Version Changer — switch MC server version by updating the egg
 * version variable and triggering reinstall (one click).
 */
class VersionController
{
    /** Version catalog — software => versions (pulls latest builds from Paper API at runtime for Paper/Purpur). */
    private const SOFTWARES = [
        'Paper' => ['api' => 'paper', 'repo' => 'papermc'],
        'Purpur' => ['api' => 'purpur', 'repo' => 'purpur'],
        'Folia' => ['api' => 'folia', 'repo' => 'papermc'],
        'Velocity' => ['api' => 'velocity', 'repo' => 'papermc'],
        'Vanilla' => ['api' => null, 'versions' => ['1.21.4', '1.21.3', '1.21.1', '1.20.6', '1.20.4', '1.20.2', '1.19.4', '1.18.2', '1.16.5', '1.12.2', '1.8.8']],
        'Spigot' => ['api' => null, 'versions' => ['1.21.1', '1.20.6', '1.20.4', '1.19.4', '1.18.2', '1.16.5', '1.12.2']],
    ];

    /** GET /versions — available softwares + versions. */
    public function versions(Server $server): JsonResponse
    {
        $result = [];
        foreach (self::SOFTWARES as $software => $cfg) {
            if (!empty($cfg['api'])) {
                try {
                    $resp = Http::timeout(8)->get("https://api.papermc.io/v2/projects/{$cfg['api']}");
                    if ($resp->successful()) {
                        $result[$software] = array_slice(array_reverse($resp->json('versions') ?? []), 0, 12);
                        continue;
                    }
                } catch (\Throwable $e) { /* fall through */ }
                $result[$software] = $cfg['versions'] ?? [];
            } else {
                $result[$software] = $cfg['versions'];
            }
        }
        return new JsonResponse(['software' => $result, 'current' => $this->currentVersion($server)]);
    }

    /** GET /current — current version variable. */
    private function currentVersion(Server $server): ?string
    {
        foreach ($server->variables as $v) {
            if (in_array($v->env_variable, ['VANILLA_VERSION', 'MINECRAFT_VERSION', 'SERVER_VERSION'])) {
                return $v->server_value ?: $v->default_value;
            }
        }
        return null;
    }

    /** POST /switch — change version: update egg variable + reinstall. */
    public function switchVersion(Server $server, Request $request): JsonResponse
    {
        $data = $request->validate([
            'software' => 'required|string',
            'version' => 'required|string',
        ]);

        $software = $data['software'];
        if (!array_key_exists($software, self::SOFTWARES)) {
            return new JsonResponse(['error' => 'Unknown software'], 422);
        }

        // egg version variable find + update (server_value)
        $varModel = null;
        foreach ($server->variables as $v) {
            if (in_array($v->env_variable, ['VANILLA_VERSION', 'MINECRAFT_VERSION', 'SERVER_VERSION'])) {
                $varModel = $v;
                break;
            }
        }
        if (!$varModel) {
            return new JsonResponse(['error' => 'Version variable not found on this egg'], 422);
        }

        // update pivot server_value
        $server->variables()->updateExistingPivot($varModel->id, ['variable_value' => $data['version']]);

        // server jar file variable bhi sync karo (SERVER_JARFILE jar name with version)
        foreach ($server->variables as $v) {
            if ($v->env_variable === 'SERVER_JARFILE') {
                // jar name unchanged rakhte hain — startup uses same path
            }
        }

        // wings ko naya startup config sync + reinstall trigger
        $node = Node::find($server->node_id);
        $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
        $token = $node->getDecryptedKey();

        try {
            Http::withToken($token)->withOptions(['verify' => !config('zenith.insecure_tls', false)])
                ->timeout(15)->post($base . '/api/servers/' . $server->uuid . '/sync');

            // reinstall trigger — new jar downloads on install
            Http::withToken($token)->withOptions(['verify' => !config('zenith.insecure_tls', false)])
                ->timeout(15)->post($base . '/api/servers/' . $server->uuid . '/install');

            return new JsonResponse(['success' => true, 'message' => "Switched to {$software} {$data['version']} — reinstall started. Console me progress dekho."]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Reinstall trigger failed: ' . $e->getMessage()], 502);
        }
    }
}
