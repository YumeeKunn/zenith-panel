<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\Console\Kernel;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\Http\Requests\Admin\ZenithFormRequest;

class ZenithController extends Controller
{
    public function __construct(
        private AlertsMessageBag $alert,
        private Kernel $kernel,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function index(): View
    {
        // live status of both services
        $watchdog = $this->serviceStatus('ptero-watchdog');
        $notifier = $this->serviceStatus('ptero-notify');

        // last actions from logs (watchdog journal + health log)
        $watchdogLast = $this->lastJournal('ptero-watchdog.service', 3);
        $notifierLast = $this->lastJournal('ptero-notify.service', 3);

        return view('admin.zenith', [
            'watchdog' => $watchdog,
            'notifier' => $notifier,
            'watchdogLast' => $watchdogLast,
            'notifierLast' => $notifierLast,
            'cpuWarn' => $this->settings->get('zenith::notify:cpu-warn', '85'),
            'memWarn' => $this->settings->get('zenith::notify:mem-warn', '90'),
            'diskWarn' => $this->settings->get('zenith::notify:disk-warn', '90'),
            'nodeCpuWarn' => $this->settings->get('zenith::notify:node-cpu-warn', '90'),
            'nodeMemWarn' => $this->settings->get('zenith::notify:node-mem-warn', '90'),
            'cooldown' => $this->settings->get('zenith::notify:cooldown', '900'),
            'wdMaxRestarts' => $this->settings->get('zenith::watchdog:max-restarts', '3'),
            'wdRestartWindow' => $this->settings->get('zenith::watchdog:restart-window', '600'),
            'wdCpuThreshold' => $this->settings->get('zenith::watchdog:cpu-threshold', '95'),
            'wdCpuMinutes' => $this->settings->get('zenith::watchdog:cpu-minutes', '5'),
            'wdOomLimit' => $this->settings->get('zenith::watchdog:oom-limit', '3'),
            'wdDiskWarn' => $this->settings->get('zenith::watchdog:disk-warn', '90'),
            'discordWebhook' => $this->settings->get('zenith::discord:webhook', ''),
            'genericWebhook' => $this->settings->get('zenith::generic:webhook', ''),
            'alertsMatrix' => $this->alertsMatrix(),
        ]);
    }

    /** Save thresholds + discord webhook (DB-backed, notifier/watchdog read these). */
    public function update(ZenithFormRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $map = [
            'notify:cpu-warn', 'notify:mem-warn', 'notify:disk-warn',
            'notify:node-cpu-warn', 'notify:node-mem-warn', 'notify:cooldown',
            'watchdog:max-restarts', 'watchdog:restart-window',
            'watchdog:cpu-threshold', 'watchdog:cpu-minutes',
            'watchdog:oom-limit', 'watchdog:disk-warn',
        ];
        foreach ($map as $short) {
            $field = str_replace([':', '-'], '_', $short);
            $val = $request->input($field);
                if ($val !== null && $val !== '') {
                try {
                    $this->settings->set('zenith::' . $short, (string) $val);
                } catch (\Throwable $e) {
                    \Log::channel('daily')->error('[zenith-ctrl] set failed for ' . $short . ': ' . $e->getMessage());
                }
            }
        }

        if ($request->has('discord_webhook')) {
            $webhook = trim($request->input('discord_webhook'));
            if ($webhook === '') {
                $this->settings->set('zenith::discord:webhook', '');
            } elseif (preg_match('#^https://(discord|discordapp)\.com/api/webhooks/[\d]+/[\w-]+$#', $webhook)) {
                $this->settings->set('zenith::discord:webhook', $webhook);
            } else {
                $this->alert->danger('Discord webhook URL format galat hai — save skip hua.');
            }
        }

        // Alert settings matrix — per type: channel dropdown + threshold
        $svc = app(\Pterodactyl\Services\Zenith\NotificationService::class);
        foreach (\Pterodactyl\Services\Zenith\NotificationService::ALERT_TYPES as $key => $def) {
            if ($request->has("alert_{$key}")) {
                $threshold = null;
                if ($def['threshold'] !== null) {
                    $threshold = $request->filled("alert_{$key}_threshold")
                        ? (int) $request->input("alert_{$key}_threshold")
                        : $def['threshold'];
                }
                $svc->setAlert($key, (string) $request->input("alert_{$key}", 'both'), $threshold);
            }
        }

        $this->alert->success('Zenith settings saved! (naye thresholds next cycle me apply honge)')->flash();
        return redirect()->route('admin.zenith');
    }

    /** Start/stop/restart watchdog or notifier timers. */
    public function service(string $service, string $action): RedirectResponse
    {
        $allowed = ['ptero-watchdog', 'ptero-notify'];
        $actions = ['start', 'stop', 'restart'];

        if (!in_array($service, $allowed) || !in_array($action, $actions)) {
            $this->alert->danger('Invalid service/action.')->flash();
            return redirect()->route('admin.zenith');
        }

        $cmd = match ($action) {
            'start' => "sudo systemctl start {$service}.timer && sudo systemctl start {$service}.service",
            'stop' => "sudo systemctl stop {$service}.timer && sudo systemctl stop {$service}.service",
            'restart' => "sudo systemctl restart {$service}.timer && sudo systemctl start {$service}.service",
        };

        $out = @shell_exec($cmd . ' 2>&1');
        \Log::channel('daily')->info('[zenith-ctrl] service=' . $service . ' action=' . $action . ' out=' . substr((string) $out, 0, 150));

        // brief settle
        usleep(500000);
        $status = $this->timerActive($service) ? 'ACTIVE' : 'STOPPED';

        $this->alert->success(ucfirst(str_replace('ptero-', '', $service)) . " {$action} ho gaya — ab: {$status}")->flash();
        return redirect()->route('admin.zenith');
    }

    /** Send a test Discord message right now. */
    /** Alert settings matrix for the UI: per-type channel + threshold. */
    private function alertsMatrix(): array
    {
        $svc = app(\Pterodactyl\Services\Zenith\NotificationService::class);
        $matrix = [];
        foreach (\Pterodactyl\Services\Zenith\NotificationService::ALERT_TYPES as $key => $def) {
            $cfg = $svc->alert($key);
            $matrix[$key] = ['label' => $def['label'], 'hint' => $def['hint'], 'hasThreshold' => $def['threshold'] !== null] + $cfg;
        }
        return $matrix;
    }

    public function testChannels(): RedirectResponse
    {
        $results = app(\Pterodactyl\Services\Zenith\NotificationService::class)->testAll();

        if (empty($results)) {
            $this->alert->danger('Koi notification channel configured nahi hai — pehle webhook/token save karo.')->flash();
        } else {
            foreach ($results as $ch => $res) {
                if ($res === 'sent') {
                    $this->alert->success(ucfirst($ch) . ': test message sent ✓')->flash();
                } else {
                    $this->alert->danger(ucfirst($ch) . ': FAILED — ' . $res)->flash();
                }
            }
        }
        return redirect()->route('admin.zenith');
    }

    private function serviceStatus(string $svc): array
    {
        return [
            'timer_active' => $this->timerActive($svc),
            'running' => trim((string) @shell_exec("sudo systemctl is-active {$svc}.service 2>/dev/null")) === 'active',
        ];
    }

    private function timerActive(string $svc): bool
    {
        return trim((string) @shell_exec("sudo systemctl is-active {$svc}.timer 2>/dev/null")) === 'active';
    }

    private function lastJournal(string $unit, int $n): array
    {
        $out = @shell_exec("sudo journalctl -u {$unit} --no-pager -n {$n} 2>/dev/null | grep -v '^--' | tail -{$n}");
        return array_filter(array_map('trim', explode("\n", (string) $out)));
    }
}
