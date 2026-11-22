<?php

namespace Subdomains\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController
{
    public function get(): JsonResponse
    {
        $store = \Subdomains\SubdomainsProvider::$settingsStore;
        $out = [];
        if ($store) {
            foreach (['cf_token', 'cf_zone', 'domain', 'server_ip'] as $k) {
                $out[$k] = $store->get($k, '');
            }
        }
        return new JsonResponse(['schema' => array_map(fn ($k, $v) => ['input' => $k, 'value' => $v], array_keys($out), $out)]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cf_token' => 'nullable|string|max:200',
            'cf_zone' => 'nullable|string|max:100',
            'domain' => 'nullable|string|max:100',
            'server_ip' => 'nullable|string|max:100',
        ]);
        $p = \Subdomains\SubdomainsProvider::$settingsStore;
        if ($p) {
            foreach ($data as $k => $v) {
                $p->set($k, $v ?? '');
            }
        }
        return new JsonResponse(['saved' => true]);
    }
}
