<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Frontend\Http\Middleware\SetLanguage;
use Tbtop\Cms\Frontend\Http\Middleware\ShareCmsProps;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Page\Enums\ErrorClass;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\RecordingFormBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    config()->set('tbtop-cms.blocks.types', [RecordingFormBlock::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    RecordingFormBlock::$handled = 0;
});

it('binds action with the current page id to a layout-slot form block, and accepts a submission to it', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'h1', 'kind' => 'Recording', 'active' => true, 'data' => ['label' => ['en' => 'Newsletter']]],
        ],
    ]);

    app(Cms::class)->frontendRoutes();

    $expectedAction = route('tbtop-cms.form.submit', ['locale' => 'en', 'page' => $page->getKey(), 'block' => 'h1']);

    $this->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.cms.layout.header.0.props.action', $expectedAction);

    $this->post($expectedAction, ['value' => 'hi'])->assertRedirect();

    $submission = FormSubmission::query()->sole();

    expect($submission->page_id)->toBe($page->getKey())
        ->and($submission->block_id)->toBe('h1')
        ->and(RecordingFormBlock::$handled)->toBe(1);
});

it('binds action to a HandlesFormSubmission block on a CMS error page', function (): void {
    $errorPage = Page::factory()->create([
        'is_system' => true,
        'error_class' => ErrorClass::ClientError,
        'status' => PageStatus::Published,
        'name' => ['en' => '404 page'],
        'content' => [
            ['id' => 'form-1', 'kind' => 'Recording', 'active' => true, 'data' => ['label' => ['en' => 'Newsletter']]],
        ],
    ]);

    app(Cms::class)->frontendRoutes();

    $expectedAction = route('tbtop-cms.form.submit', ['locale' => 'en', 'page' => $errorPage->getKey(), 'block' => 'form-1']);

    $this->get('/nonexistent', ['X-Inertia' => 'true'])
        ->assertNotFound()
        ->assertJsonPath('props.render.elements.form-1.props.action', $expectedAction);
});

it('binds a layout-slot form action to the home page id when the request has no current CMS page', function (): void {
    $home = Page::factory()->create(['is_system' => true, 'status' => PageStatus::Published, 'name' => ['en' => 'Home']]);

    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'h1', 'kind' => 'Recording', 'active' => true, 'data' => ['label' => ['en' => 'Newsletter']]],
        ],
    ]);

    // A host route sharing `cms` via ShareCmsProps directly, without ever
    // running PageController or ErrorResponse — CurrentPage stays unset.
    // Registered before frontendRoutes() so it wins the catch-all match;
    // frontendRoutes() still registers the named submit route this needs.
    Route::middleware([SetLanguage::class, ShareCmsProps::class])
        ->get('{route?}', fn () => Inertia::render('HostOwned/Page', []))
        ->where('route', '.*');

    app(Cms::class)->frontendRoutes();

    $expectedAction = route('tbtop-cms.form.submit', ['locale' => 'en', 'page' => $home->getKey(), 'block' => 'h1']);

    $this->get('/host-owned-route', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.cms.layout.header.0.props.action', $expectedAction);
});

it('leaves a layout-slot form block unbound when neither a current page nor a home page exists', function (): void {
    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [
            ['id' => 'h1', 'kind' => 'Recording', 'active' => true, 'data' => ['label' => ['en' => 'Newsletter']]],
        ],
    ]);

    Route::middleware([SetLanguage::class, ShareCmsProps::class])
        ->get('{route?}', fn () => Inertia::render('HostOwned/Page', []))
        ->where('route', '.*');

    $response = $this->get('/host-owned-route', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.cms.layout.header.0.props'))->not->toHaveKey('action');
});
