<?php

namespace Mcplugins\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;

/**
 * MC Plugins — browse Modrinth plugins for the server's MC version,
 * download into the server's plugins directory via wings file API.
 */
class PluginController
{
    private const MODRINTH = 'https://api.modrinth.com/v2';

    /** GET /search?query=...&version=1.21 — search Modrinth plugins (bukkit/spigot/paper loaders). */
    public function search(Server $server, Request $request): JsonResponse
    {
        $query = $request->query('query', '');
        $resp = Http::withHeaders(['User-Agent' => 'ZenithPanel/1.0'])->timeout(15)
            ->get(self::MODRINTH . '/search', [
                'query' => $query,
                'facets' => json_encode([['categories:paper', 'categories:spigot', 'categories:bukkit'], ['project_type:plugin']]),
                'limit' => 20,
            ]);
        if (!$resp->successful()) {
            return new JsonResponse(['error' => 'Modrinth search failed'], 502);
        }
        $results = array_map(fn ($h) => [
            'slug' => $h['slug'],
            'title' => $h['title'],
            'description' => substr($h['description'] ?? '', 0, 120),
            'downloads' => $h['downloads'] ?? 0,
            'icon' => $h['icon_url'] ?? '',
        ], $resp->json('hits') ?? []);

        return new JsonResponse(['results' => $results]);
    }

    /** GET /versions/{slug}?mc=1.21 — available plugin versions for MC version. */
    public function versions(Server $server, string $slug, Request $request): JsonResponse
    {
        $mc = $request->query('mc', '');
        $resp = Http::withHeaders(['User-Agent' => 'ZenithPanel/1.0'])->timeout(15)
            ->get(self::MODRINTH . "/project/{$slug}/version", ['loaders' => ['paper', 'spigot', 'bukkit']]);
        if (!$resp->successful()) {
            return new JsonResponse(['error' => 'Modrinth versions failed'], 502);
        }
        $versions = array_map(fn ($v) => [
            'id' => $v['id'],
            'version_number' => $v['version_number'],
            'game_versions' => $v['game_versions'] ?? [],
            'file_url' => $v['files'][0]['url'] ?? '',
            'file_name' => $v['files'][0]['filename'] ?? '',
        ], array_filter($resp->json() ?? [], fn ($v) => !$mc || in_array($mc, $v['game_versions'] ?? [])));

        return new JsonResponse(['versions' => array_values($versions)]);
    }

    /** POST /install — download plugin jar into server plugins dir via wings remote download. */
    public function install(Server $server, Request $request): JsonResponse
    {
        $data = $request->validate(['url' => 'required|url', 'filename' => 'required|string|max:190']);

        // wings pull endpoint — remote file into plugins dir
        $node = $server->node;
        $base = $node->scheme . '://' . $node->fqdn . ':' . $node->daemonListen;
        try {
            Http::withToken($node->getDecryptedKey())
                ->withOptions(['verify' => !config('zenith.insecure_tls', false)])
                ->timeout(30)
                ->post($base . '/api/servers/' . $server->uuid . '/files/download', [
                    'url' => $data['url'],
                    'directory' => '/plugins',
                ]);
            return new JsonResponse(['success' => true, 'message' => $data['filename'] . ' plugins/ me download ho gaya. Restart karo to load hoga.']);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }
    }
}
