<?php

namespace Minecraftplayermanager\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;

class StatusController
{
    // The Scribe attribute documents this endpoint for the panel's OpenAPI
    // generation. Scribe is a dev-only dependency there; PHP never autoloads
    // attribute classes unless they are reflected, so shipping it is safe.
    #[Endpoint('Get status', 'Returns a heartbeat for the MC Player Manager extension.')]
    public function __invoke(): JsonResponse
    {
        // Persisted per-extension settings are available via:
        // \Pterodactyl\Facades\Extensions::settings('minecraftplayermanager')
        return new JsonResponse([
            'extension' => 'minecraftplayermanager',
            'status' => 'ok',
        ]);
    }
}
