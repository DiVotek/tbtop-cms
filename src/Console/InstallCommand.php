<?php

declare(strict_types=1);

namespace Tbtop\Cms\Console;

use Illuminate\Console\Command;
use Tbtop\Cms\Database\Seeders\LanguageSeeder;
use Tbtop\Cms\Database\Seeders\PageSeeder;

class InstallCommand extends Command
{
    protected $signature = 'cms:install {--force : Overwrite published config files} {--skip-migrations : Do not run database migrations}';

    protected $description = 'Install the CMS: publish config, run migrations, and seed default languages and the home page.';

    public function handle(): int
    {
        $this->publishConfig();
        $this->runMigrations();
        $this->seed();
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

    private function runMigrations(): void
    {
        if ($this->option('skip-migrations')) {
            return;
        }

        $this->call('migrate', ['--force' => true]);
    }

    private function seed(): void
    {
        (new LanguageSeeder)->run();
        (new PageSeeder)->run();
        $this->info('Seeded default languages and ensured the home page.');
    }

    private function nextSteps(): void
    {
        $this->newLine();
        $this->info('Next: create your first admin user with');
        $this->line('  php artisan cms:admin:create you@example.com');
    }
}
