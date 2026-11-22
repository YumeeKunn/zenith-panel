<?php

namespace ZenithMonitor\Services;

use Pterodactyl\Models\Setting;

/** Extension settings store (settings table, zenith-monitor:* keys). */
class Settings
{
    public static function get(string $short): ?string
    {
        $v = Setting::where('key', 'zenith-monitor:' . $short)->value('value');
        return ($v !== null && $v !== '') ? $v : null;
    }

    public static function set(string $short, string $value): void
    {
        Setting::updateOrCreate(['key' => 'zenith-monitor:' . $short], ['value' => $value]);
    }
}
