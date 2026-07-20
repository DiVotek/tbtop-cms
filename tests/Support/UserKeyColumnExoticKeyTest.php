<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Tests\Fixtures\ExoticKeyAdminUser;

afterEach(function (): void {
    DB::purge('exotic_key_host_test');
});

it('throws a clear exception when the host user model uses a key type the helper does not support', function (): void {
    config(['tbtop-cms.admin.model' => ExoticKeyAdminUser::class]);

    config(['database.connections.exotic_key_host_test' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => false,
    ]]);

    Schema::connection('exotic_key_host_test')->create('users', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->timestamps();
    });

    $previousDefault = config('database.default');
    config(['database.default' => 'exotic_key_host_test']);

    try {
        (require __DIR__ . '/../../database/migrations/create_cms_user_profiles_table.php')->up();
    } finally {
        config(['database.default' => $previousDefault]);
    }
})->throws(RuntimeException::class, ExoticKeyAdminUser::class);
