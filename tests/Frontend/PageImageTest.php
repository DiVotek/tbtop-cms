<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tbtop\Admin\Media\Models\Media;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Storage::fake('public');
    Language::factory()->default()->create(['locale' => 'en']);

    app(Cms::class)->frontendRoutes();
});

function pageImageMedia(string $name): Media
{
    return Media::create([
        'name' => $name,
        'disk' => 'public',
        'path' => "media/{$name}",
        'mime' => 'image/jpeg',
        'size' => 123,
    ]);
}

it('resolves the page image id to a public url in the Inertia props', function (): void {
    $image = pageImageMedia('page-image.jpg');
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'image' => (string) $image->getKey(),
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.page.image', Storage::disk('public')->url('media/page-image.jpg'));
});

it('ships a null page image when none is set', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'image' => null,
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.page.image', null);
});

it('ships a null page image when the stored media id no longer exists', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'title' => ['en' => 'About Page'],
        'image' => '999999',
    ]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.page.image', null);
});
