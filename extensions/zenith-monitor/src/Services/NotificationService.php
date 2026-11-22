<?php

namespace ZenithMonitor\Services;

use Illuminate\Support\Facades\Http;

/**
 * Multi-channel notifications: Discord + Generic webhook.
 * Settings extension store se aate hain (Zenith Monitor admin settings).
 */
class NotificationService
{
    public const ALERT_TYPES = [
        'node_down' => ['label' => 'Node Down', 'threshold' => null, 'hint' => 'Node offline or unreachable'],
        'node_cpu' => ['label' => 'Node CPU High', 'threshold' => 90, 'hint' => 'Host CPU usage'],
        'node_ram' => ['label' => 'Node RAM High', 'threshold' => 90, 'hint' => 'Host RAM usage'],
        'server_cpu' => ['label' => 'Server CPU Sustained', 'threshold' => 85, 'hint' => 'Game server CPU (auto-suspend)'],
        'server_ram' => ['label' => 'Server RAM High', 'threshold' => 92, 'hint' => 'Game server RAM (OOM tracking)'],
        'server_disk' => ['label' => 'Server Disk Full', 'threshold' => 90, 'hint' => 'Game server disk limit'],
        'host_disk' => ['label' => 'Host Disk Full', 'threshold' => 90, 'hint' => 'Node machine disk'],
        'backup' => ['label' => 'Backup Stale', 'threshold' => null, 'hint' => 'Daily DB backup stalled'],
        'watchdog' => ['label' => 'Watchdog Actions', 'threshold' => null, 'hint' => 'Auto-suspend events'],
        'wings_health' => ['label' => 'Wings Daemon Health', 'threshold' => null, 'hint' => 'Wings RAM ballooning / CPU spikes'],
        'resolved' => ['label' => 'Recovered / Resolved', 'threshold' => null, 'hint' => 'Green message when issue clears'],
    ];

    public const CHANNELS = ['off', 'discord', 'generic', 'both'];

    /** Get alert config: ['channel' => string, 'threshold' => int|null]. */
    public function alert(string $type): array
    {
        $defaults = self::ALERT_TYPES[$type] ?? ['threshold' => null];
        $raw = Settings::get('alert:' . $type);
        $decoded = $raw ? json_decode($raw, true) : null;

        return [
            'channel' => $decoded['channel'] ?? 'both',
            'threshold' => $decoded['threshold'] ?? $defaults['threshold'],
        ];
    }

    public function setAlert(string $type, string $channel, ?int $threshold = null): void
    {
        $payload = ['channel' => in_array($channel, self::CHANNELS) ? $channel : 'both'];
        if ((self::ALERT_TYPES[$type]['threshold'] ?? null) !== null) {
            $payload['threshold'] = $threshold ?? self::ALERT_TYPES[$type]['threshold'];
        }
        Settings::set('alert:' . $type, json_encode($payload));
    }

    public function wantsDiscord(string $type): bool
    {
        return in_array($this->alert($type)['channel'], ['discord', 'both']);
    }

    public function wantsGeneric(string $type): bool
    {
        return in_array($this->alert($type)['channel'], ['generic', 'both']);
    }

    public function threshold(string $type): ?float
    {
        $t = $this->alert($type)['threshold'];
        return $t !== null ? (float) $t : null;
    }

    public function cooldown(): int
    {
        return (int) (Settings::get('cooldown') ?? 900);
    }

    /** Send to channels configured for the alert type. Returns per-channel results. */
    public function send(string $title, string $text, int $color = 0xE67E22, string $type = 'general'): array
    {
        $results = [];
        $plain = $title . "\n" . str_replace(['**', '`'], '', $text);

        if ($this->wantsDiscord($type)) {
            $webhook = Settings::get('discord_webhook');
            if ($webhook) {
                try {
                    $r = Http::timeout(10)->post($webhook, ['embeds' => [[
                        'title' => $title,
                        'description' => $text,
                        'color' => $color,
                        'footer' => ['text' => 'Zenith Monitor • ' . now()->format('d M Y H:i') . ' UTC'],
                    ]]]);
                    $results['discord'] = $r->successful() ? 'sent' : 'HTTP ' . $r->status();
                } catch (\Throwable $e) {
                    $results['discord'] = 'error: ' . $e->getMessage();
                }
            }
        }

        if ($this->wantsGeneric($type)) {
            $generic = Settings::get('generic_webhook');
            if ($generic) {
                try {
                    $r = Http::timeout(10)->post($generic, [
                        'title' => $title,
                        'message' => $plain,
                        'type' => $type,
                        'severity' => $color === 0xED4245 ? 'critical' : ($color === 0x57F287 ? 'info' : 'warning'),
                        'timestamp' => now()->toIso8601String(),
                    ]);
                    $results['generic'] = $r->successful() ? 'sent' : 'HTTP ' . $r->status();
                } catch (\Throwable $e) {
                    $results['generic'] = 'error: ' . $e->getMessage();
                }
            }
        }

        return $results;
    }

    public function testAll(): array
    {
        $results = [];
        $plain = '✅ Zenith Monitor — test message. Notifications working!';

        $webhook = Settings::get('discord_webhook');
        if ($webhook) {
            try {
                $r = Http::timeout(10)->post($webhook, ['embeds' => [[
                    'title' => '✅ Zenith Monitor — test',
                    'description' => $plain,
                    'color' => 0x57F287,
                    'footer' => ['text' => 'Zenith Monitor test'],
                ]]]);
                $results['discord'] = $r->successful() ? 'sent' : 'HTTP ' . $r->status();
            } catch (\Throwable $e) {
                $results['discord'] = 'error: ' . $e->getMessage();
            }
        }

        $generic = Settings::get('generic_webhook');
        if ($generic) {
            try {
                $r = Http::timeout(10)->post($generic, [
                    'title' => '✅ Zenith Monitor — test',
                    'message' => $plain,
                    'severity' => 'info',
                ]);
                $results['generic'] = $r->successful() ? 'sent' : 'HTTP ' . $r->status();
            } catch (\Throwable $e) {
                $results['generic'] = 'error: ' . $e->getMessage();
            }
        }

        return $results;
    }
}
