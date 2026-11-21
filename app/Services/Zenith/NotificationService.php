<?php

namespace Pterodactyl\Services\Zenith;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Setting;

/**
 * Zenith NotificationService — simple, flexible alert settings.
 *
 * Alert types: har type ke liye admin set karta hai (Zenith Control):
 *   - channel: 'off' | 'discord' | 'generic' | 'both'  → kahan bhejna hai
 *   - threshold: percentage (load/disk types ke liye)  → kitne % pe alert
 *
 * Storage: settings key 'zenith::alert:<type>' = {"channel":"discord","threshold":85}
 * Missing = default (channel: 'both', threshold: type-specific default).
 */
class NotificationService
{
    /** Alert types with labels + default thresholds. */
    public const ALERT_TYPES = [
        'node_down' => ['label' => 'Node Down', 'threshold' => null, 'hint' => 'Node offline ya unreachable hone pe'],
        'node_cpu' => ['label' => 'Node CPU High', 'threshold' => 90, 'hint' => 'Poore node (host) ka CPU'],
        'node_ram' => ['label' => 'Node RAM High', 'threshold' => 90, 'hint' => 'Poore node (host) ki RAM'],
        'server_cpu' => ['label' => 'Server CPU High', 'threshold' => 85, 'hint' => 'Kisi ek game server ka CPU'],
        'server_ram' => ['label' => 'Server RAM High', 'threshold' => 92, 'hint' => 'Kisi ek game server ki RAM'],
        'server_disk' => ['label' => 'Server Disk Full', 'threshold' => 90, 'hint' => 'Game server ki disk limit'],
        'host_disk' => ['label' => 'Host Disk Full', 'threshold' => 90, 'hint' => 'Node machine ki disk'],
        'backup' => ['label' => 'Backup Stale', 'threshold' => null, 'hint' => 'Daily backup 26h+ ruk jaye to'],
        'watchdog' => ['label' => 'Watchdog Actions', 'threshold' => null, 'hint' => 'Auto-suspend hote waqt'],
        'wings_health' => ['label' => 'Wings Daemon Health', 'threshold' => null, 'hint' => 'Wings RAM ballooning / CPU spikes'],
        'resolved' => ['label' => 'Recovered / Resolved', 'threshold' => null, 'hint' => 'Issue clear hone pe green message'],
    ];

    public const CHANNELS = ['off', 'discord', 'generic', 'both'];


    /** Get alert config: ['channel' => string, 'threshold' => int|null]. */
    public function alert(string $type): array
    {
        $defaults = self::ALERT_TYPES[$type] ?? ['threshold' => null];
        $raw = $this->settings->get('zenith::alert:' . $type);
        $decoded = $raw ? json_decode($raw, true) : null;

        return [
            'channel' => $decoded['channel'] ?? 'both',
            'threshold' => $decoded['threshold'] ?? $defaults['threshold'],
        ];
    }

    /** Save alert config (channel + optional threshold). */
    public function setAlert(string $type, string $channel, ?int $threshold = null): void
    {
        $payload = ['channel' => in_array($channel, self::CHANNELS) ? $channel : 'both'];
        if (self::ALERT_TYPES[$type]['threshold'] !== null) {
            $payload['threshold'] = $threshold ?? self::ALERT_TYPES[$type]['threshold'];
        }
        Setting::updateOrCreate(['key' => 'zenith::alert:' . $type], ['value' => json_encode($payload)]);
    }

    /** Should this alert type go to Discord? */
    public function wantsDiscord(string $type): bool
    {
        return in_array($this->alert($type)['channel'], ['discord', 'both']);
    }

    /** Should this alert type go to the generic webhook? */
    public function wantsGeneric(string $type): bool
    {
        return in_array($this->alert($type)['channel'], ['generic', 'both']);
    }

    /** Threshold for a load-type alert. */
    public function threshold(string $type): ?float
    {
        $t = $this->alert($type)['threshold'];
        return $t !== null ? (float) $t : null;
    }

    /**
     * Send an alert through the channels configured for its type.
     * Returns per-channel results (empty = channel off / not configured).
     */
    public function send(string $title, string $text, int $color = 0xE67E22, string $type = 'general'): array
    {
        $results = [];
        $plain = $title . "\n" . str_replace(['**', '`'], '', $text);

        // Discord webhook
        if ($this->wantsDiscord($type)) {
            $webhook = $this->db('discord:webhook') ?: config('zenith.discord_webhook');
            if (!empty($webhook)) {
                try {
                    $r = Http::timeout(10)->post($webhook, ['embeds' => [[
                        'title' => $title,
                        'description' => $text,
                        'color' => $color,
                        'footer' => ['text' => 'Zenith Panel • ' . now()->format('d M Y H:i') . ' UTC'],
                    ]]]);
                    $results['discord'] = $r->successful() ? 'sent' : 'HTTP ' . $r->status();
                } catch (\Throwable $e) {
                    $results['discord'] = 'error: ' . $e->getMessage();
                }
            }
        }

        // Generic JSON webhook (ntfy.sh, Slack-compatible, custom)
        if ($this->wantsGeneric($type)) {
            $generic = $this->db('generic:webhook') ?: config('zenith.generic_webhook');
            if (!empty($generic)) {
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

    /** Send a test ping to every CONFIGURED channel (ignores per-type settings). */
    public function testAll(): array
    {
        $results = [];
        $plain = '✅ Zenith Panel — test message. Notifications working!';

        $webhook = $this->db('discord:webhook') ?: config('zenith.discord_webhook');
        if (!empty($webhook)) {
            try {
                $r = Http::timeout(10)->post($webhook, ['embeds' => [[
                    'title' => '✅ Zenith Panel — test',
                    'description' => $plain,
                    'color' => 0x57F287,
                    'footer' => ['text' => 'Zenith Control test'],
                ]]]);
                $results['discord'] = $r->successful() ? 'sent' : 'HTTP ' . $r->status();
            } catch (\Throwable $e) {
                $results['discord'] = 'error: ' . $e->getMessage();
            }
        }

        $generic = $this->db('generic:webhook') ?: config('zenith.generic_webhook');
        if (!empty($generic)) {
            try {
                $r = Http::timeout(10)->post($generic, [
                    'title' => '✅ Zenith Panel — test',
                    'message' => $plain,
                    'severity' => 'info',
                    'timestamp' => now()->toIso8601String(),
                ]);
                $results['generic'] = $r->successful() ? 'sent' : 'HTTP ' . $r->status();
            } catch (\Throwable $e) {
                $results['generic'] = 'error: ' . $e->getMessage();
            }
        }

        return $results;
    }

    private function db(string $short): ?string
    {
        $v = Setting::where('key', 'zenith::' . $short)->value('value');
        return ($v !== null && $v !== '') ? $v : null;
    }
}
