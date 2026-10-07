<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tbtop\Admin\Http\SetCurrentPanel;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Admin\Media\Models\MediaFolder;
use Tbtop\Cms\Admin\Http\Middleware\AuthorizeMediaApi;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    Storage::fake('public');

    $user = AdminUser::create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    test()->actingAs($user, 'web');
});

/** @param list<string> $granted */
function grantSections(array $granted): void
{
    config(['tbtop-cms.roles' => true]);
    foreach ($granted as $ability) {
        Gate::define($ability, static fn (): bool => true);
    }
}

function seededMedia(): Media
{
    return Media::create([
        'name' => 'photo.jpg',
        'disk' => 'public',
        'path' => 'tbtop-media/photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 123,
        'alt' => 'Original alt',
    ]);
}

it('lets a page editor without media read and add, but not change or delete', function (): void {
    grantSections(['tbtop-cms.pages']);
    $media = seededMedia();
    $folder = MediaFolder::create(['name' => 'Originals']);

    test()->getJson('/admin/api/media')->assertOk();
    test()->getJson("/admin/api/media/{$media->id}")->assertOk();
    test()->getJson('/admin/api/media/folders')->assertOk();
    test()->post('/admin/api/media/upload', ['file' => UploadedFile::fake()->image('new.jpg')], ['Accept' => 'application/json'])
        ->assertCreated();
    test()->postJson('/admin/api/media/folders', ['name' => 'New folder'])->assertCreated();

    test()->patchJson("/admin/api/media/{$media->id}", ['alt' => 'Changed'])->assertForbidden();
    test()->deleteJson("/admin/api/media/{$media->id}")->assertForbidden();
    test()->post("/admin/api/media/{$media->id}/replace", ['file' => UploadedFile::fake()->image('other.jpg')], ['Accept' => 'application/json'])
        ->assertForbidden();
    test()->patchJson("/admin/api/media/folders/{$folder->id}", ['name' => 'Renamed'])->assertForbidden();
    test()->deleteJson("/admin/api/media/folders/{$folder->id}")->assertForbidden();

    expect($media->fresh())->not->toBeNull()
        ->and($media->fresh()->alt)->toBe('Original alt')
        ->and($media->fresh()->path)->toBe('tbtop-media/photo.jpg')
        ->and($folder->fresh()?->name)->toBe('Originals');
});

it('lets a media holder change and delete', function (): void {
    grantSections(['tbtop-cms.media']);
    $media = seededMedia();
    $folder = MediaFolder::create(['name' => 'Originals']);

    test()->patchJson("/admin/api/media/{$media->id}", ['alt' => 'Changed'])->assertOk();
    test()->patchJson("/admin/api/media/folders/{$folder->id}", ['name' => 'Renamed'])->assertOk();
    test()->deleteJson("/admin/api/media/folders/{$folder->id}")->assertNoContent();
    test()->deleteJson("/admin/api/media/{$media->id}")->assertNoContent();

    expect(Media::query()->count())->toBe(0);
});

it('refuses the media API to a user with no section that has a media field', function (): void {
    grantSections(['tbtop-cms.submissions', 'tbtop-cms.redirects']);

    test()->getJson('/admin/api/media')->assertForbidden();
    test()->getJson('/admin/api/media/folders')->assertForbidden();
});

it('keeps the media API open to every admin when roles are off', function (): void {
    config(['tbtop-cms.roles' => false]);
    $media = seededMedia();
    $folder = MediaFolder::create(['name' => 'Originals']);

    test()->getJson('/admin/api/media')->assertOk();
    test()->patchJson("/admin/api/media/{$media->id}", ['alt' => 'Changed'])->assertOk();
    test()->patchJson("/admin/api/media/folders/{$folder->id}", ['name' => 'Renamed'])->assertOk();
    test()->deleteJson("/admin/api/media/folders/{$folder->id}")->assertNoContent();
    test()->deleteJson("/admin/api/media/{$media->id}")->assertNoContent();
});

// Ties the map to tbtop/admin's real route list: a renamed or new media
// route fails here instead of silently falling back to "media only".
it('maps every media API route the admin package registers', function (): void {
    $prefix = 'tbtop.cms.media.';
    $registered = collect(Route::getRoutes()->getRoutesByName())
        ->keys()
        ->filter(static fn (string $name): bool => str_starts_with($name, $prefix))
        ->map(static fn (string $name): string => Str::after($name, $prefix))
        ->sort()->values()->all();

    expect($registered)->toBe(collect(array_keys(AuthorizeMediaApi::ROUTES))->sort()->values()->all());
});

it('requires media for a media API route missing from the map', function (): void {
    Route::middleware([SetCurrentPanel::class . ':cms', 'web', 'auth:web', AuthorizeMediaApi::class])
        ->get('/_probe/media-unmapped', static fn (): string => 'ok')
        ->name('tbtop.cms.media.unmapped-probe');
    Route::getRoutes()->refreshNameLookups();

    grantSections(['tbtop-cms.pages']);
    test()->getJson('/_probe/media-unmapped')->assertForbidden();

    Gate::define('tbtop-cms.media', static fn (): bool => true);
    test()->getJson('/_probe/media-unmapped')->assertOk();
});
