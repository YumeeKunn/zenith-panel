<?php

namespace Pterodactyl\Console\Commands;

use Illuminate\Console\Command;

/**
 * Zenith backport (v2-style announcements): set/remove global banner text.
 */
class AnnounceCommand extends Command
{
    protected $signature = 'zenith:announce
        {text? : Announcement text (empty to remove)}
        {--type=info : Banner type: info|success|warning|danger}';

    protected $description = 'Set or remove the global announcement banner';

    public function handle(): int
    {
        $text = $this->argument('text');
        $type = $this->option('type');
        $envFile = $this->laravel->environmentFilePath();
        $content = file_get_contents($envFile);

        $write = function (string $key, ?string $value) use (&$content): void {
            if (preg_match("/^{$key}=.*$/m", $content)) {
                $content = preg_replace("/^{$key}=.*$/m", $value !== null ? "{$key}={$value}" : '', $content);
            } elseif ($value !== null) {
                $content = rtrim($content) . "\n{$key}={$value}\n";
            }
        };

        if (empty($text)) {
            $write('ZENITH_ANNOUNCEMENT', null);
            $this->info('Announcement removed.');
        } else {
            $write('ZENITH_ANNOUNCEMENT', '"' . str_replace(["\n", '"'], [' ', '\"'], $text) . '"');
            $write('ZENITH_ANNOUNCEMENT_TYPE', $type);
            $this->info("Announcement set (type: {$type}): {$text}");
        }

        file_put_contents($envFile, $content);
        $this->call('config:clear');
        return 0;
    }
}
