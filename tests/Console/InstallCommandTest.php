<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Console\InstallCommand;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

afterEach(fn () => forgetPublishedConfig());

it('prepares default languages, a home page and the two error pages on a fresh app', function (): void {
    $this->artisan('cms:install')->assertExitCode(0);

    expect(Language::count())->toBe(1)
        ->and(Page::where('is_system', true)->count())->toBe(3);
});

it('is safe to run twice without duplicating data', function (): void {
    $this->artisan('cms:install')->assertExitCode(0);
    $this->artisan('cms:install')->assertExitCode(0);

    expect(Language::count())->toBe(1)
        ->and(Page::where('is_system', true)->count())->toBe(3);
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

it('stops installation when configuration publishing fails', function (): void {
    $command = Mockery::mock(InstallCommand::class)->makePartial();
    $command->__construct();
    $command->shouldReceive('callSilently')
        ->once()
        ->with('vendor:publish', ['--tag' => 'tbtop-cms-config'])
        ->andReturn(1);
    $this->app->make(Kernel::class)->registerCommand($command);

    $this->artisan('cms:install')
        ->expectsOutput('Configuration publishing failed. Resolve the issue above and re-run `cms:install`.')
        ->doesntExpectOutput('Published the CMS config.')
        ->assertExitCode(1);

    expect(Language::count())->toBe(0)
        ->and(Page::count())->toBe(0);
});

it('prints the @cms alias as valid vite and tsconfig snippets', function (): void {
    $this->artisan('cms:install')
        ->expectsOutputToContain("resolve: { alias: { '@cms': path.resolve(__dirname, 'vendor/tbtop/cms/resources/js/cms') } },")
        ->expectsOutputToContain('{ "compilerOptions": { "paths": { "@cms/*": ["./vendor/tbtop/cms/resources/js/cms/*"] } } }')
        ->doesntExpectOutputToContain("'@cms' => ")
        ->assertExitCode(0);
});
