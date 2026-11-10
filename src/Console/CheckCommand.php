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
        $this->userColumn($config, $column);
        $this->write();

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function userColumn(Repository $config, string $column): void
    {
        $provider = $config->get('auth.guards.' . $config->get('auth.defaults.guard') . '.provider');
        $model = is_string($provider) ? $config->get("auth.providers.{$provider}.model") : null;

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            $this->row('user_locale', CheckStatus::warn, "no Eloquent user model to check [{$column}] on");

            return;
        }

        $user = new $model;
        $table = $user->getTable();

        try {
            $exists = Schema::connection($user->getConnectionName())->hasColumn($table, $column);
        } catch (QueryException) {
            $this->row('user_locale', CheckStatus::warn, "could not reach the database to check {$table}.{$column}");

            return;
        }

        if ($exists) {
            $this->row('user_locale', CheckStatus::pass, "{$table}.{$column}");
        } else {
            $this->row('user_locale', CheckStatus::fail, "{$table} has no column [{$column}]");
        }
    }

    private function row(string $check, CheckStatus $status, string $detail): void
    {
        $this->failed = $this->failed || $status === CheckStatus::fail;
        $this->write('  ' . str_pad("{$check} ", 16, '.') . " {$status->value} {$detail}");
    }

    /** Raw, so a configured name like `<fg=red>` isn't read as a style tag. */
    private function write(string $line = ''): void
    {
        $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
    }
}
