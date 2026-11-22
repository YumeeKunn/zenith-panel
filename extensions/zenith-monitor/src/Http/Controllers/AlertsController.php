<?php

namespace ZenithMonitor\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ZenithMonitor\Services\NotificationService;
use ZenithMonitor\Services\Settings;

class AlertsController
{
    public function __construct(private NotificationService $notify) {}

    public function index(): JsonResponse
    {
        $svc = $this->notify;
        $alerts = [];
        foreach (NotificationService::ALERT_TYPES as $key => $def) {
            $alerts[$key] = [
                'label' => $def['label'],
                'hint' => $def['hint'],
                'has_threshold' => $def['threshold'] !== null,
                'threshold_default' => $def['threshold'],
            ] + $svc->alert($key);
        }

        return new JsonResponse([
            'discord_webhook' => \ZenithMonitor\Services\Settings::get('discord_webhook') ?? '',
            'generic_webhook' => \ZenithMonitor\Services\Settings::get('generic_webhook') ?? '',
            'cooldown' => \ZenithMonitor\Services\Settings::get('cooldown') ?? 900,
            'modules' => json_decode(\ZenithMonitor\Services\Settings::get('modules') ?? '', true) ?? ['watchdog' => true, 'graphs' => true, 'usage' => true],
            'alerts' => $alerts,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'discord_webhook' => 'nullable|string|max:500',
            'generic_webhook' => 'nullable|string|max:500',
            'cooldown' => 'nullable|integer|min:60',
            'modules' => 'nullable|array',
            'modules.watchdog' => 'nullable|boolean',
            'modules.graphs' => 'nullable|boolean',
            'modules.usage' => 'nullable|boolean',
            'alerts' => 'nullable|array',
            'alerts.*.channel' => 'required_with:alerts|in:off,discord,generic,both',
            'alerts.*.threshold' => 'nullable|integer|min:1',
        ]);

        foreach (['discord_webhook', 'generic_webhook'] as $field) {
            if (array_key_exists($field, $data)) {
                \ZenithMonitor\Services\Settings::set($field, $data[$field] ?? '');
            }
        }
        if (!empty($data['cooldown'])) {
            \ZenithMonitor\Services\Settings::set('cooldown', (string) $data['cooldown']);
        }
        if (isset($data['modules'])) {
            \ZenithMonitor\Services\Settings::set('modules', json_encode([
                'watchdog' => (bool) ($data['modules']['watchdog'] ?? false),
                'graphs' => (bool) ($data['modules']['graphs'] ?? false),
                'usage' => (bool) ($data['modules']['usage'] ?? false),
            ]));
        }
        foreach ($data['alerts'] ?? [] as $type => $cfg) {
            if (array_key_exists($type, NotificationService::ALERT_TYPES)) {
                $def = NotificationService::ALERT_TYPES[$type];
                $this->notify->setAlert($type, $cfg['channel'], $def['threshold'] !== null ? ($cfg['threshold'] ?? $def['threshold']) : null);
            }
        }

        return new JsonResponse(['saved' => true]);
    }

    public function test(): JsonResponse
    {
        return new JsonResponse(['results' => $this->notify->testAll()]);
    }
}
