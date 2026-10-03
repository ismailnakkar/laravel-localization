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

    private bool $failed;

    public function handle(Repository $config): int
    {
        $this->failed = false;
        $column = UserLocale::column();

        if (Locales::configured() === null || $column === null) {
            return self::SUCCESS;
        }

        $this->write('languages');
        $this->row('user_locale', ...self::userColumn($config, $column));
        $this->write();

        return $this->failed ? self::FAILURE : self::SUCCESS;
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
