<?php

namespace Startupchanger\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Models\Setting;

class PresetController
{
    public function index(): JsonResponse
    {
        $presets = Setting::where('key', 'like', 'startupchanger:preset:%')
            ->get()->mapWithKeys(fn ($s) => [str_replace('startupchanger:preset:', '', $s->key) => $s->value]);
        return new JsonResponse(['presets' => $presets]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => 'required|string|max:60', 'startup' => 'required|string|max:500']);
        foreach (['rm -rf', 'dd ', 'mkfs'] as $bad) {
            if (stripos($data['startup'], $bad) !== false) {
                return new JsonResponse(['error' => 'Blocked pattern in startup'], 422);
            }
        }
        Setting::updateOrCreate(['key' => 'startupchanger:preset:' . $data['key']], ['value' => $data['startup']]);
        return new JsonResponse(['saved' => true]);
    }

    public function delete(string $key): JsonResponse
    {
        Setting::where('key', 'startupchanger:preset:' . $key)->delete();
        return new JsonResponse(['deleted' => true]);
    }
}
