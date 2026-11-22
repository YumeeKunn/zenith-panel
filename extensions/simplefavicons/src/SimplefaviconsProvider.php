<?php

namespace Simplefavicons;

use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class SimplefaviconsProvider extends ExtensionProvider
{
    private static ?\Pterodactyl\Services\Extensions\ExtensionSettings $settingsStore = null;

    public static function store(): ?\Pterodactyl\Services\Extensions\ExtensionSettings
    {
        return self::$settingsStore;
    }
    public function register(): void
    {
    }

    public function boot(): void
    {
        self::$settingsStore = $this->settings();
        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('url', 'url', '', ['nullable', 'string', 'max:500'])
                ->label('Favicon URL')
                ->help('Custom favicon shown in the browser tab.')
                ->field('text'),
        ]));

        $this->registerApiRoutes();

        $favicon = $this->settings()->get('url');
        if ($favicon) {
            $this->registerHeadTags([
                ['tag' => 'link', 'attributes' => ['rel' => 'icon', 'id' => 'dynamic-favicon', 'href' => $favicon]],
            ]);
        }
    }
}
