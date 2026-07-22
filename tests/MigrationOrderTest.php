<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function migrationIndexOf(array $names, string $suffix): int
{
    foreach ($names as $index => $name) {
        if (str_contains($name, $suffix)) {
            return $index;
        }
    }

    throw new RuntimeException("No migration found matching suffix: {$suffix}");
}

it('orders migrations so foreign-key dependents load after the tables they reference', function (): void {
    $migrator = $this->app['migrator'];
    $files = $migrator->getMigrationFiles($migrator->paths());
    $names = array_keys($files);

    $pages = migrationIndexOf($names, 'create_pages_table');
    $formSubmissions = migrationIndexOf($names, 'create_cms_form_submissions_table');
    $pageRevisions = migrationIndexOf($names, 'create_page_revisions_table');
    $keyTypeAlignment = migrationIndexOf($names, 'update_user_id_columns_match_host_key_type');
    $userProfilesForeignKey = migrationIndexOf($names, 'update_cms_user_profiles_add_user_id_foreign_table');

    expect($pages)->toBeLessThan($formSubmissions)
        ->and($pages)->toBeLessThan($pageRevisions)
        ->and($keyTypeAlignment)->toBeLessThan($userProfilesForeignKey);
});

it('re-running migrations against an already-migrated database is a no-op', function (): void {
    DB::table('migrations')->where('migration', 'like', '2026_01_01_%')->delete();

    $this->artisan('migrate')->assertExitCode(0);
});
