<?php

namespace ZenithMonitor;

use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use ZenithMonitor\Services\WatchdogService;

class ZenithMonitorProvider extends ExtensionProvider
{
    public function register(): void
    {
        $this->app->singleton(WatchdogService::class);
        $this->app->singleton(NotificationService::class);
    }

    public function boot(): void
    {
        $this->registerApiRoutes();

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            // Notification channels
            ExtensionSettingDefinition::make('discord_webhook', 'discord_webhook', '', ['nullable', 'string', 'max:500'])
                ->label('Discord Webhook URL')
                ->help('Node/server alerts is channel pe jayenge. Channel → Integrations → Webhooks → copy URL.')
                ->field('text'),
            ExtensionSettingDefinition::make('generic_webhook', 'generic_webhook', '', ['nullable', 'string', 'max:500'])
                ->label('Generic Webhook URL')
                ->help('ntfy.sh topic ya koi JSON endpoint. POST {title, message, severity, type}.')
                ->field('text'),
            ExtensionSettingDefinition::make('cooldown', 'cooldown', 900, ['required', 'integer', 'min:60', 'max:86400'])
                ->label('Alert Repeat Interval (seconds)')
                ->help('Same alert iske baad repeat hoga (anti-spam).')
                ->field('number'),
            // Watchdog thresholds
            ExtensionSettingDefinition::make('node_cpu_warn', 'node_cpu_warn', 90, ['required', 'integer', 'min:1', 'max:100'])
                ->label('Node CPU alert at (%)')
                ->help('Host CPU iske upar gaya to alert.')
                ->field('number'),
            ExtensionSettingDefinition::make('node_ram_warn', 'node_ram_warn', 90, ['required', 'integer', 'min:1', 'max:100'])
                ->label('Node RAM alert at (%)')
                ->help('Host RAM iske upar gaya to alert.')
                ->field('number'),
            ExtensionSettingDefinition::make('server_cpu_warn', 'server_cpu_warn', 85, ['required', 'integer', 'min:1', 'max:1000'])
                ->label('Server CPU suspend at (%) sustained')
                ->help('Game server sustained CPU iske upar → auto-suspend.')
                ->field('number'),
            ExtensionSettingDefinition::make('server_ram_warn', 'server_ram_warn', 92, ['required', 'integer', 'min:1', 'max:100'])
                ->label('Server RAM alert at (%)')
                ->help('Game server RAM iske upar → alert + OOM tracking.')
                ->field('number'),
            ExtensionSettingDefinition::make('max_restarts', 'max_restarts', 4, ['required', 'integer', 'min:1', 'max:10'])
                ->label('Crash-loop limit')
                ->help('Itne crashes in 10 minutes → auto-suspend.')
                ->field('number'),
            ExtensionSettingDefinition::make('wings_rss_mb', 'wings_rss_mb', 2048, ['required', 'integer', 'min:256', 'max:16384'])
                ->label('Wings RAM alert at (MB)')
                ->help('Wings daemon RAM ballooning alert (#5115).')
                ->field('number'),
            ExtensionSettingDefinition::make('backup_stale_hours', 'backup_stale_hours', 26, ['required', 'integer', 'min:2', 'max:168'])
                ->label('Backup stale after (hours)')
                ->help('Last DB backup itne ghante purana → alert.')
                ->field('number'),
        ]));

        // Watchdog tick — har minute (panel scheduler chalna chahiye)
        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->call(fn () => $this->app->make(WatchdogService::class)->tick())
                ->name('zenith-monitor:tick')
                ->everyMinute()
                ->withoutOverlapping(10);
        });
    }
}
