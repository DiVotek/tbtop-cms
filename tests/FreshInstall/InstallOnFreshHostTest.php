<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('installs cleanly on a host that has never run a migration', function (): void {
    $this->artisan('cms:install')->assertExitCode(0);
});

it('preserves an unrelated host pages table during installation', function (): void {
    Schema::create('pages', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
    });
    DB::table('pages')->insert(['title' => 'Host page']);

    $this->artisan('cms:install')->assertExitCode(0);

    expect(Schema::hasTable('pages'))->toBeTrue()
        ->and(DB::table('pages')->value('title'))->toBe('Host page')
        ->and(Schema::hasTable('tbtop_pages'))->toBeTrue();
});
