<?php

declare(strict_types=1);

namespace Localization\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Localization\Locales;
use Localization\UserLocale;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
#[AsCommand(name: 'localization:check')]
final class CheckCommand extends Command
{
    protected $signature = 'localization:check';

    protected $description = 'Check the language configuration a booted app can only check at runtime.';

    private const array LEFTOVER_KEYS = ['seo.locales', 'seo.user_locale'];

    private bool $failed;

    public function handle(Repository $config): int
    {
        $this->failed = false;
        $leftover = array_values(array_filter(self::LEFTOVER_KEYS, static fn (string $key): bool => filled($config->get($key))));

        // Only false: true was the old default; an ignored false silently re-enables remembering.
        if ($config->get('seo.remember_locale') === false) {
            $leftover[] = 'seo.remember_locale';
        }
        $off = $config->get('localization.remember_locale') === false;
        $stale = filled($config->get('localization.entry_redirect'));
        $column = UserLocale::column();
        $languages = Locales::configured() !== null && ($stale || $column !== null);

        if ($leftover === [] && ! $languages) {
            return self::SUCCESS;
        }

        $this->write('languages');

        if ($leftover !== []) {
            $this->row('leftover keys', 'FAIL', implode(', ', $leftover) . ': move them to config/localization.php, laravel-seo ignores them');
        }

        if ($languages) {
            $this->languages($config, $off, $stale, $column);
        }

        $this->write();

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function languages(Repository $config, bool $off, bool $stale, ?string $column): void
    {
        if ($stale) {
            $this->row('entry_redirect', 'WARN', 'ignored since 0.2, remove it: every localized page now redirects to a known language');
        }

        if ($column !== null) {
            $this->row('user_locale', ...($off ? ['WARN', 'ignored while remember_locale is false'] : self::userColumn($config, $column)));
        }
    }

    /** @return array{string, string} status, detail */
    private static function userColumn(Repository $config, string $column): array
    {
        $provider = $config->get('auth.guards.' . $config->get('auth.defaults.guard') . '.provider');
        $model = is_string($provider) ? $config->get("auth.providers.{$provider}.model") : null;

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            return ['WARN', "no Eloquent user model to check [{$column}] on"];
        }

        $user = new $model;
        $table = $user->getTable();

        try {
            $exists = Schema::connection($user->getConnectionName())->hasColumn($table, $column);
        } catch (QueryException) {
            return ['WARN', "could not reach the database to check {$table}.{$column}"];
        }

        return $exists ? ['PASS', "{$table}.{$column}"] : ['FAIL', "{$table} has no column [{$column}]"];
    }

    private function row(string $check, string $status, string $detail): void
    {
        $this->failed = $this->failed || $status === 'FAIL';
        $this->write('  ' . str_pad("{$check} ", 16, '.') . " {$status} {$detail}");
    }

    /** Raw, so a configured name like `<fg=red>` isn't read as a style tag. */
    private function write(string $line = ''): void
    {
        $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
    }
}
