<?php

namespace Minecraftpluginmanager\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;

/**
 * Plugin Manager v2 — CurseForge-style search across Modrinth
 * with server MC version filtering + bulk install.
 */
class ModpackController
{
    private const MODRINTH = 'https://api.modrinth.com/v2';

    public function search(Server $server, Request $request): JsonResponse
    {
        $q = $request->query('query', '');
        $facet = $request->query('type', 'plugin'); // plugin | mod | modpack
        $resp = Http::withHeaders(['User-Agent' => 'ZenithPanel/1.0'])->timeout(15)
            ->get(self::MODRINTH . '/search', [
                'query' => $q,
                'facets' => json_encode([["categories:{$facet}", "project_type:{$facet}"]]),
                'limit' => 20,
            ]);
        $hits = array_map(fn ($h) => [
            'slug' => $h['slug'], 'title' => $h['title'],
            'downloads' => $h['downloads'] ?? 0,
        ], $resp->json('hits') ?? []);
        return new JsonResponse(['results' => $hits]);
    }
}
