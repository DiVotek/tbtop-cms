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
        if (! $this->publishConfig()) {
            return self::FAILURE;
        }

        if (! $this->runMigrations()) {
            return self::FAILURE;
        }

        if (! $this->seed()) {
            return self::FAILURE;
        }

        $this->nextSteps();

        return self::SUCCESS;
    }

    private function publishConfig(): bool
    {
        $arguments = ['--tag' => 'tbtop-cms-config'];

        if ($this->option('force')) {
            $arguments['--force'] = true;
        }

        try {
            $exitCode = $this->callSilently('vendor:publish', $arguments);
        } catch (Throwable $e) {
            $this->error("Configuration publishing failed: {$e->getMessage()}");

            return false;
        }

        if ($exitCode !== self::SUCCESS) {
            $this->error('Configuration publishing failed. Resolve the issue above and re-run `cms:install`.');

            return false;
        }

        $this->info('Published the CMS config.');

        return true;
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

    /**
     * The host wiring this package cannot write for itself.
     *
     * Every line below lands in a file the host or tbtop/admin owns — a build
     * config, the frontend root view, the admin entry `admin:install`
     * publishes. Editing those from here is how a package silently breaks an
     * application it does not understand, so they are printed instead, and
     * `cms:doctor` re-checks each one against the filesystem afterwards.
     */
    private function nextSteps(): void
    {
        $this->newLine();
        $this->info('Next: create your first admin user with');
        $this->line('  php artisan cms:admin:create you@example.com');

        $this->newLine();
        $this->warn('Then wire the host up — none of these live in files this package owns:');
        $this->newLine();

        $this->line('  1. Register the frontend routes (routes/web.php):');
        $this->line('       use Tbtop\\Cms\\Facades\\Cms;');
        $this->line('       Cms::frontendRoutes();');
        $this->newLine();

        $this->line('  2. Render the SEO head in your frontend root view (resources/views/app.blade.php),');
        $this->line('     above the Inertia head tag:');
        $this->line("       @include('tbtop-cms::head')");
        $this->newLine();

        $this->line('  3. Point the @cms alias at this package (vite.config.ts and tsconfig.json):');
        $this->line("       '@cms' => 'vendor/tbtop/cms/resources/js/cms'");
        $this->newLine();

        $this->line('  4. Register the admin blocks from your admin entry (resources/js/admin.tsx):');
        $this->line("       import '@cms/admin/blocks';");
        $this->newLine();

        $this->line('  5. Render pages and layout slots with CmsRenderer, never the bare @json-render Renderer,');
        $this->line('     so a block that fails to resolve or render shows a placeholder instead of vanishing:');
        $this->line("       import { CmsRenderer } from '@cms/CmsRenderer';");
        $this->line('       <CmsRenderer spec={render} catalog={catalog} registry={registry} showErrors={isPreview || cms.debug} />');
        $this->newLine();

        $this->line('  6. Let Tailwind scan this package (resources/css/admin.css):');
        $this->line("       @source '../../vendor/tbtop/cms/src/**/*.php';");
        $this->line("       @source '../../vendor/tbtop/cms/resources/js/cms/admin/**/*.tsx';");
        $this->newLine();

        $this->line('  7. Delete public/robots.txt if your skeleton shipped one — Laravel serves files');
        $this->line('     under public/ before any route runs, so it shadows the dynamic /robots.txt.');
        $this->newLine();

        $this->line('  8. Point an agent building on this host at the host-builder guide — add this line');
        $this->line('     to your AGENTS.md or CLAUDE.md:');
        $this->line('       Building on tbtop/cms → read vendor/tbtop/cms/docs/host/ai/README.md first.');
        $this->newLine();

        $this->line('  Then run `php artisan cms:doctor` — it verifies each step above.');
    }
}
