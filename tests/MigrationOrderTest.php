<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

/** new tbtop_-prefixed name => legacy name, mirroring the 000000 rename migration. */
const LEGACY_TABLE_NAMES = [
    'tbtop_pages' => 'pages',
    'tbtop_page_slugs' => 'page_slugs',
    'tbtop_page_revisions' => 'page_revisions',
    'tbtop_languages' => 'languages',
    'tbtop_redirects' => 'redirects',
    'tbtop_user_profiles' => 'cms_user_profiles',
    'tbtop_settings' => 'cms_settings',
    'tbtop_form_submissions' => 'cms_form_submissions',
    'tbtop_layouts' => 'cms_layouts',
];

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

    // The tbtop_ prefix rename must run before every guarded create/update
    // migration — they reference the new names only, so a legacy database
    // has to be renamed first (see the 000000 migration's docblock).
    $prefixRename = migrationIndexOf($names, 'update_tables_add_tbtop_prefix');
    $firstCreate = migrationIndexOf($names, 'create_languages_table');

    expect($prefixRename)->toBeLessThan($firstCreate);
});

it('upgrades a legacy-named database in place, renaming before guarded migrations run', function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Page::factory()->create(['name' => ['en' => 'Legacy page']]);

    // Simulate a database created before the tbtop_ prefix existed: legacy
    // table names on disk and no record of the package migrations.
    foreach (LEGACY_TABLE_NAMES as $new => $old) {
        Schema::rename($new, $old);
    }
    DB::table('migrations')->where('migration', 'like', '2026_01_01_%')->delete();

    $this->artisan('migrate')->assertExitCode(0);

    // The rename ran first, so the guarded creates saw the new names and
    // skipped instead of creating empty duplicates; data is intact.
    expect(Schema::hasTable('tbtop_pages'))->toBeTrue()
        ->and(Schema::hasTable('pages'))->toBeFalse()
        ->and(Page::query()->count())->toBe(1)
        ->and(Language::query()->count())->toBe(1);
});

it('re-running migrations against an already-migrated database is a no-op', function (): void {
    DB::table('migrations')->where('migration', 'like', '2026_01_01_%')->delete();

    $this->artisan('migrate')->assertExitCode(0);
});
