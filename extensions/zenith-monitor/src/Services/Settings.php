<?php

namespace ZenithMonitor\Services;

/**
 * Access to the extension's settings store.
 * The provider registers itself here at boot so services can reach the
 * built-in ExtensionSettings store (encrypted secrets supported).
 */
class Settings
{
    public static ?\Pterodactyl\Services\Extensions\ExtensionSettings $store = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$store === null) {
            return $default;
        }
        $v = self::$store->get($key, $default);
        return $v === null ? $default : (is_scalar($v) ? (string) $v : json_encode($v));
    }

    public static function set(string $key, string $value): void
    {
        self::$store?->set($key, $value);
    }
}
