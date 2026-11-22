<?php

namespace Startupchanger\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;

/**
 * Startup Changer — let server owners switch between startup command
 * presets safely (admin defines allowed patterns; malicious commands blocked).
 */
class StartupController
{
    /** GET — current startup + admin-defined presets. */
    public function show(Server $server): JsonResponse
    {
        $presets = \Pterodactyl\Models\Setting::where('key', 'like', 'startupchanger:preset:%')->get()
            ->mapWithKeys(fn ($s) => [str_replace('startupchanger:preset:', '', $s->key) => $s->value]);

        return new JsonResponse([
            'startup' => $server->startup,
            'presets' => $presets,
        ]);
    }

    /** POST — apply a preset or custom startup (validated). */
    public function update(Server $server, Request $request): JsonResponse
    {
        $data = $request->validate(['startup' => 'required|string|max:500']);

        // malicious pattern block — dangerous commands rejected
        foreach (['rm -rf', 'dd ', 'mkfs', ':(){', 'wget http', 'curl http |', 'chmod 777 /'] as $bad) {
            if (stripos($data['startup'], $bad) !== false) {
                return new JsonResponse(['error' => 'Startup command contains a blocked pattern'], 422);
            }
        }

        $server->startup = $data['startup'];
        $server->save();

        // wings sync
        $node = $server->node;
        \Illuminate\Support\Facades\Http::withToken($node->getDecryptedKey())
            ->withOptions(['verify' => !config('zenith.insecure_tls', false)])
            ->timeout(10)
            ->post($node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen . '/api/servers/' . $server->uuid . '/sync');

        return new JsonResponse(['saved' => true, 'startup' => $server->startup]);
    }
}
