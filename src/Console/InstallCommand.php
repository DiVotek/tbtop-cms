<?php

declare(strict_types=1);

namespace Tbtop\Cms\Console;

use Illuminate\Console\Command;
use Tbtop\Cms\Database\Seeders\LanguageSeeder;
use Tbtop\Cms\Database\Seeders\PageSeeder;
use Throwable;

class InstallCommand extends Command
{
    protected $signature = 'cms:install {--force : Overwrite published config files} {--skip-migrations : Do not run database migrations}';

    protected $description = 'Install the CMS: publish config, run migrations, and seed default languages and the home page.';

    public function handle(): int
    {
        $this->publishConfig();

        if (! $this->runMigrations()) {
            return self::FAILURE;
        }

        if (! $this->seed()) {
            return self::FAILURE;
        }

        $this->nextSteps();

        return self::SUCCESS;
    }

    private function publishConfig(): void
    {
        $arguments = ['--tag' => 'tbtop-cms-config'];

        if ($this->option('force')) {
            $arguments['--force'] = true;
        }

        $this->callSilently('vendor:publish', $arguments);
        $this->info('Published the CMS config.');
    }

    private function runMigrations(): bool
    {
        if ($this->option('skip-migrations')) {
            return true;
        }

        try {
            $exitCode = $this->call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            $this->error("Migration failed: {$e->getMessage()}");

            return false;
        }

        if ($exitCode !== self::SUCCESS) {
            $this->error('Migration failed. Resolve the issue above and re-run `cms:install`.');

            return false;
        }

        return true;
    }

    private function seed(): bool
    {
        try {
            (new LanguageSeeder)->run();
            (new PageSeeder)->run();
        } catch (Throwable $e) {
            $this->error("Seeding failed: {$e->getMessage()}");

            return false;
        }

        $this->info('Seeded default languages and ensured the home page.');

        return true;
    }

    private function nextSteps(): void
    {
        $this->newLine();
        $this->info('Next: create your first admin user with');
        $this->line('  php artisan cms:admin:create you@example.com');
    }
}
