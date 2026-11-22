<?php

namespace Subdomains\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * SubDomains — create A/SRV records on Cloudflare pointing to the server.
 * Admin configures Cloudflare API token + zone (extension settings).
 */
class SubdomainController
{
    private function cfConfig(): array
    {
        return [
            'token' => Setting::where('key', 'subdomains:cf_token')->value('value'),
            'zone' => Setting::where('key', 'subdomains:cf_zone')->value('value'),
            'domain' => Setting::where('key', 'subdomains:domain')->value('value'),
            'server_ip' => Setting::where('key', 'subdomains:server_ip')->value('value'),
        ];
    }

    public function config(): JsonResponse
    {
        $cfg = $this->cfConfig();
        return new JsonResponse(['configured' => !empty($cfg['token']) && !empty($cfg['zone']), 'domain' => $cfg['domain']]);
    }

    public function create(Server $server, Request $request): JsonResponse
    {
        $cfg = $this->cfConfig();
        if (empty($cfg['token']) || empty($cfg['zone'])) {
            return new JsonResponse(['error' => 'Cloudflare not configured by admin'], 422);
        }
        $data = $request->validate(['sub' => 'required|string|regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', 'port' => 'nullable|integer|min:1|max:65535']);
        $port = $data['port'] ?? $server->allocation->port;
        $fqdn = $data['sub'] . '.' . $cfg['domain'];

        // SRV record for MC (_minecraft._tcp.sub.domain) + A record
        $create = function ($type, $name, $content) use ($cfg) {
            return Http::withToken($cfg['token'])->timeout(15)
                ->post("https://api.cloudflare.com/client/v4/zones/{$cfg['zone']}/dns_records", [
                    'type' => $type, 'name' => $name, 'content' => $content, 'ttl' => 3600,
                ]);
        };
        $aResp = $create('A', $fqdn, $cfg['server_ip']);
        if (!$aResp->successful()) {
            $errors = $aResp->json('errors.0.message') ?? 'Cloudflare error';
            return new JsonResponse(['error' => $errors], 502);
        }
        if ($type = 'minecraft') {
            $srv = $create('SRV', "_minecraft._tcp.{$fqdn}", "0 10 {$port} {$fqdn}");
        }

        return new JsonResponse(['success' => true, 'fqdn' => $fqdn, 'port' => $port]);
    }
}
