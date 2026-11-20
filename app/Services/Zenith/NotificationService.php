<?php

namespace Pterodactyl\Services\Zenith;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

/**
 * Zenith NotificationService — multi-channel alert fan-out.
 *
 * Supported channels (any combination, configured via Zenith Control):
 *   - Discord webhook   (embeds, colored)
 *   - LINE Messaging API (plain text push)
 *   - Telegram bot      (plain text, markdown-lite)
 *   - Generic webhook   (POST JSON — ntfy.sh, Slack-compatible, custom)
 *
 * All channel configs read from settings table (Zenith Control editable)
 * with config()/env fallback.
 */
class NotificationService
{
    private SettingsRepositoryInterface $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    /** Send alert to ALL configured channels. Returns per-channel results. */
    public function send(string $title, string $text, int $color = 0xE67E22): array
    {
        $results = [];

        // Discord
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

        // LINE Messaging API (plain text — strip markdown)
        $lineToken = $this->db('line:token') ?: config('zenith.line_token');
        $lineTo = $this->db('line:user') ?: config('zenith.line_user');
        if (!empty($lineToken) && !empty($lineTo)) {
            try {
                $plain = $title . "\n" . strip_tags(str_replace(['**', '`'], '', $text));
                $r = Http::withToken($lineToken)->timeout(10)
                    ->post('https://api.line.me/v2/bot/message/push', [
                        'to' => $lineTo,
                        'messages' => [['type' => 'text', 'text' => mb_substr($plain, 0, 4900)]],
                    ]);
                $results['line'] = $r->successful() ? 'sent' : 'HTTP ' . $r->status();
            } catch (\Throwable $e) {
                $results['line'] = 'error: ' . $e->getMessage();
            }
        }

        // Telegram bot
        $tgToken = $this->db('telegram:token') ?: config('zenith.telegram_token');
        $tgChat = $this->db('telegram:chat') ?: config('zenith.telegram_chat');
        if (!empty($tgToken) && !empty($tgChat)) {
            try {
                $plain = $title . "\n" . str_replace(['**', '`'], '', $text);
                $r = Http::timeout(10)->post("https://api.telegram.org/bot{$tgToken}/sendMessage", [
                    'chat_id' => $tgChat,
                    'text' => mb_substr($plain, 0, 4000),
                ]);
                $results['telegram'] = $r->successful() ? 'sent' : 'HTTP ' . $r->status();
            } catch (\Throwable $e) {
                $results['telegram'] = 'error: ' . $e->getMessage();
            }
        }

        // Generic webhook (ntfy.sh, Slack-compatible, custom receivers)
        $generic = $this->db('generic:webhook') ?: config('zenith.generic_webhook');
        if (!empty($generic)) {
            try {
                $plain = $title . "\n" . str_replace(['**', '`'], '', $text);
                $r = Http::timeout(10)->post($generic, [
                    'title' => $title,
                    'message' => $plain,
                    'severity' => $color === 0xED4245 ? 'critical' : ($color === 0x57F287 ? 'info' : 'warning'),
                    'timestamp' => now()->toIso8601String(),
                ]);
                $results['generic'] = $r->successful() ? 'sent' : 'HTTP ' . $r->status();
            } catch (\Throwable $e) {
                $results['generic'] = 'error: ' . $e->getMessage();
            }
        }

        return $results;
    }

    /** Send a test ping to every configured channel, return results. */
    public function testAll(): array
    {
        return $this->send(
            '✅ Zenith Panel — test message',
            'Notifications configured successfully! Isi channel pe ab alerts aayenge.',
            0x57F287
        );
    }

    private function db(string $short): ?string
    {
        $v = $this->settings->get('zenith::' . $short);
        return ($v !== null && $v !== '') ? $v : null;
    }
}
