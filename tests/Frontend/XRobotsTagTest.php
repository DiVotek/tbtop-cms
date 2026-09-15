<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

// Asked as Inertia requests: the header is set before the response picks a
// format, and a full HTML render needs a built Vite manifest CI does not have.

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

it('does not tag an indexable published page', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Open'],
        'is_indexable' => true,
    ]);

    $this->get('/open', ['X-Inertia' => 'true'])->assertOk()->assertHeaderMissing('X-Robots-Tag');
});

it('tags a page marked not indexable', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Hidden'],
        'is_indexable' => false,
    ]);

    $this->get('/hidden', ['X-Inertia' => 'true'])->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
});

it('tags every page when site-wide indexing is off', function (): void {
    app(Settings::class)->set('seo.indexing_enabled', false);

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Open'],
        'is_indexable' => true,
    ]);

    $this->get('/open', ['X-Inertia' => 'true'])->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
});

it('tags a not-live page shown through preview access', function (): void {
    // The not-live verdict is the controller's, not SeoResolver's: a header
    // recomputed from the resolver would leave a draft preview indexable.
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    Page::factory()->create([
        'status' => PageStatus::Draft,
        'name' => ['en' => 'Secret'],
        'is_indexable' => true,
    ]);

    $this->actingAs($user)->get('/secret', ['X-Inertia' => 'true'])
        ->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
});
