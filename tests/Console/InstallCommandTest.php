<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

afterEach(function (): void {
    $published = config_path('tbtop-cms.php');

    if (is_file($published)) {
        unlink($published);
    }
});

it('prepares default languages and a single home page on a fresh app', function (): void {
    $this->artisan('cms:install')->assertExitCode(0);

    expect(Language::count())->toBe(1)
        ->and(Page::where('is_system', true)->count())->toBe(1);
});

it('is safe to run twice without duplicating data', function (): void {
    $this->artisan('cms:install')->assertExitCode(0);
    $this->artisan('cms:install')->assertExitCode(0);

    expect(Language::count())->toBe(1)
        ->and(Page::where('is_system', true)->count())->toBe(1);
});

it('stops installation when config publishing fails', function (): void {
    $command = new class extends Command
    {
        protected $signature = 'vendor:publish {--tag=} {--force}';

        public function handle(): int
        {
            return self::FAILURE;
        }
    };

    $this->app->make(Kernel::class)->registerCommand($command);

    $this->artisan('cms:install')
        ->expectsOutput('Publishing the CMS config failed. Resolve the issue above and re-run `cms:install`.')
        ->doesntExpectOutput('Published the CMS config.')
        ->assertExitCode(1);

    expect(Language::count())->toBe(0)
        ->and(Page::count())->toBe(0);
});

it('fails gracefully instead of throwing when migrations cannot run', function (): void {
    config(['database.connections.broken' => ['driver' => 'invalid-driver-xyz']]);
    config(['database.default' => 'broken']);

    try {
        $this->artisan('cms:install')->assertExitCode(1);
    } finally {
        config(['database.default' => 'testing']);
    }
});
