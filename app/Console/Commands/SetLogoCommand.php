<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;

/**
 * Zenith backport of v2 "panel logo" feature (ReplacesBrandLogo).
 * Stores logo URL in config via env var ZENITH_BRAND_LOGO_URL.
 */
class SetLogoCommand extends Command
{
    protected $signature = 'zenith:logo {url? : Logo image URL (empty to remove)}';
    protected $description = 'Set or remove the panel brand logo (shown in navbar + login)';

    public function handle(): int
    {
        $url = $this->argument('url');

        $envFile = $this->laravel->environmentFilePath();
        $content = file_get_contents($envFile);

        if (empty($url)) {
            $content = preg_replace('/^ZENITH_BRAND_LOGO_URL=.*$/m', '', $content);
            $this->info('Logo removed.');
        } else {
            // basic URL validation
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->error('Invalid URL: ' . $url);
                return 1;
            }
            $key = 'ZENITH_BRAND_LOGO_URL';
            if (preg_match("/^{$key}=.*$/m", $content)) {
                $content = preg_replace("/^{$key}=.*$/m", "{$key}={$url}", $content);
            } else {
                $content = rtrim($content) . "\n{$key}={$url}\n";
            }
            $this->info('Logo set: ' . $url);
        }

        file_put_contents($envFile, $content);
        $this->call('config:clear');
        return 0;
    }
}
