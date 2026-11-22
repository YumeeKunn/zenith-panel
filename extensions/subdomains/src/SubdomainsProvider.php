<?php

namespace Subdomains;

use Pterodactyl\Extensions\ExtensionProvider;

class SubdomainsProvider extends ExtensionProvider
{
    public static ?\Pterodactyl\Services\Extensions\ExtensionSettings $settingsStore = null;
    public function register(): void
    {
        // Container bindings go here.
    }

    public function boot(): void
    {
        self::$settingsStore = $this->settings();
        $this->registerSettings(new \Pterodactyl\Services\Extensions\ExtensionSettingsDefinition($this->settings(), [
            \Pterodactyl\Services\Extensions\ExtensionSettingDefinition::make('cf_token', 'cf_token', '', ['nullable', 'string', 'max:200'])->label('Cloudflare API Token')->field('password')->secret(),
            \Pterodactyl\Services\Extensions\ExtensionSettingDefinition::make('cf_zone', 'cf_zone', '', ['nullable', 'string', 'max:100'])->label('Cloudflare Zone ID')->field('text'),
            \Pterodactyl\Services\Extensions\ExtensionSettingDefinition::make('domain', 'domain', '', ['nullable', 'string', 'max:100'])->label('Domain')->help('Users get sub.yourdomain.com')->field('text'),
            \Pterodactyl\Services\Extensions\ExtensionSettingDefinition::make('server_ip', 'server_ip', '', ['nullable', 'string', 'max:100'])->label('Server IP')->field('text'),
        ]));

        // Mounts routes/client.php at /api/client/extensions/subdomains — and
        // routes/server.php (per-server, at /api/client/servers/{server}/extensions/subdomains),
        // routes/admin.php + routes/application.php at their prefixes when added.
        $this->registerApiRoutes();

        // Uncomment as the extension grows (database/migrations runs without a call):
        // $this->loadExtensionTranslations();
        // $this->registerCommands([...]) and $this->registerSchedule(fn (Schedule $schedule) => ...)
        // $this->wrapAction(Contract::class, fn (Contract $inner): Contract => new Wrapper($inner));
        // $this->registerFields(User::class, UserFields::class) to add fields to the admin user forms
        // $this->registerHeadTags([['tag' => 'meta', 'name' => 'theme-color', 'content' => '#0f172a']]);
        // $this->registerRootRoutes(...) for prefixes claimed under "routes.root" in extension.json
    }
}
