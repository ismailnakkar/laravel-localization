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
        $stale = array_filter([
            'entry_redirect'  => filled($config->get('localization.entry_redirect')) ? 'ignored since 0.2, remove it: every localized page now redirects to a known language' : null,
            'remember_locale' => $config->get('localization.remember_locale') !== null ? 'ignored since 0.3, remove it: the language is always remembered' : null,
        ]);
        $column = UserLocale::column();
        $languages = Locales::configured() !== null && ($stale !== [] || $column !== null);

        if ($leftover === [] && ! $languages) {
            return self::SUCCESS;
        }

        $this->write('languages');

        if ($leftover !== []) {
            $this->row('leftover keys', 'FAIL', implode(', ', $leftover) . ': move them to config/localization.php, laravel-seo ignores them');
        }

        if ($languages) {
            $this->languages($config, $stale, $column);
        }

        $this->write();

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array<string, string>  $stale  key => why it is ignored */
    private function languages(Repository $config, array $stale, ?string $column): void
    {
        foreach ($stale as $key => $detail) {
            $this->row($key, 'WARN', $detail);
        }

        if ($column !== null) {
            $this->row('user_locale', ...self::userColumn($config, $column));
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
