<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    test()->actingAs($user, 'web');
});

it('mounts the media library route and emits the mediaLibrary node', function (): void {
    $structure = test()->get('/admin/media-library', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.structure');

    expect(mediaFirstKind($structure, 'mediaLibrary'))->not->toBeNull();
});

it('shows the media library in the content nav group with a localized label', function (): void {
    $nav = test()->get('/admin/media-library', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.tbtop.nav');

    $content = collect($nav)->firstWhere('key', 'content');
    expect($content)->not->toBeNull();

    $labels = array_column($content['items'], 'label', 'href');
    expect($labels['/admin/media-library'] ?? null)->toBe(__('tbtop-cms::nav.items.media_library'));
});

/** @return array<string, mixed>|null First node of the given kind in the structure. */
function mediaFirstKind(array $node, string $kind): ?array
{
    if (($node['kind'] ?? null) === $kind) {
        return $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        $found = mediaFirstKind($child, $kind);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}
