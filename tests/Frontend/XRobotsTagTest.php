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

    $this->get('/open')->assertOk()->assertHeaderMissing('X-Robots-Tag');
});

it('tags a page marked not indexable', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Hidden'],
        'is_indexable' => false,
    ]);

    $this->get('/hidden')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
});

it('tags every page when site-wide indexing is off', function (): void {
    app(Settings::class)->set('seo.indexing_enabled', false);

    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Open'],
        'is_indexable' => true,
    ]);

    $this->get('/open')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
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

    $this->actingAs($user)->get('/secret')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
});
