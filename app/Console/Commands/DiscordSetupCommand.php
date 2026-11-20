<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Zenith Discord setup helper — validates webhook or bot token + channel,
 * writes to .env, sends a test message.
 */
class DiscordSetupCommand extends Command
{
    protected $signature = 'zenith:discord-setup
        {--webhook= : Discord webhook URL}
        {--bot-token= : Discord bot token}
        {--channel= : Discord channel ID (with bot token)}';

    protected $description = 'Configure Discord notifications (webhook or bot) + test';

    public function handle(): int
    {
        $webhook = $this->option('webhook');
        $botToken = $this->option('bot-token');
        $channel = $this->option('channel');

        if (!$webhook && !$botToken) {
            $this->line('Discord notifications setup — do tarika:');
            $this->line('');
            $this->line('  <info>Option A (aasan): WEBHOOK</info>');
            $this->line('    1. Discord me apna channel kholo (#zenith-alerts)');
            $this->line('    2. Channel name pe right-click → Edit Channel → Integrations → Webhooks');
            $this->line('    3. "New Webhook" → Copy URL');
            $this->line('    4. Run: <comment>php artisan zenith:discord-setup --webhook="URL"</comment>');
            $this->line('');
            $this->line('  <info>Option B: BOT TOKEN</info>');
            $this->line('    Run: <comment>php artisan zenith:discord-setup --bot-token="TOKEN" --channel="CHANNEL_ID"</comment>');
            $this->line('    Bot ko guild me "Send Messages" permission ke saath invite karna hoga.');
            return 0;
        }

        if ($webhook) {
            if (!preg_match('#^https://(discord|discordapp)\.com/api/webhooks/[\d]+/[\w-]+$#', $webhook)) {
                $this->error('Webhook URL format galat lag raha hai (https://discord.com/api/webhooks/ID/TOKEN)');
                return 1;
            }
            $r = Http::timeout(10)->post($webhook, ['content' => '✅ Zenith Panel notifications configured!']);
            if (!$r->successful()) {
                $this->error('Webhook test fail: HTTP ' . $r->status());
                return 1;
            }
            $this->writeEnv('ZENITH_DISCORD_WEBHOOK', $webhook);
            $this->info('✅ Webhook set + test message bhej diya! Ab har 2 min me alerts aayenge.');
            return 0;
        }

        if ($botToken && $channel) {
            $r = Http::withToken($botToken)->timeout(10)
                ->post('https://discord.com/api/v10/channels/' . $channel . '/messages',
                    ['content' => '✅ Zenith Panel notifications configured!']);
            if (!$r->successful()) {
                $this->error('Bot test fail: HTTP ' . $r->status() . ' — ' . substr($r->body(), 0, 100));
                $this->line('Check: bot guild me hai? channel ID sahi? Send Messages permission?');
                return 1;
            }
            $this->writeEnv('ZENITH_DISCORD_BOT_TOKEN', $botToken);
            $this->writeEnv('ZENITH_DISCORD_CHANNEL_ID', $channel);
            $this->info('✅ Bot + channel set + test message bhej diya!');
            return 0;
        }

        $this->error('Bot token ke saath channel ID bhi chahiye (--channel=ID)');
        return 1;
    }

    private function writeEnv(string $key, string $value): void
    {
        $f = $this->laravel->environmentFilePath();
        $s = file_get_contents($f);
        $val = (str_contains($value, ' ')) ? '"' . $value . '"' : $value;
        if (preg_match("/^{$key}=.*$/m", $s)) {
            $s = preg_replace("/^{$key}=.*$/m", "{$key}={$val}", $s);
        } else {
            $s = rtrim($s) . "\n{$key}={$val}\n";
        }
        file_put_contents($f, $s);
        $this->call('config:clear');
    }
}
