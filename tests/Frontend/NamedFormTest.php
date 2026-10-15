<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\NamedFormBlock;
use Tbtop\Cms\Tests\Fixtures\NamedFormTwinBlock;
use Tbtop\Cms\Tests\Fixtures\RecordingFormBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array', 'tbtop-cms.forms.honeypot_field' => 'website']);
    config()->set('tbtop-cms.blocks.types', [NamedFormBlock::class, RecordingFormBlock::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    NamedFormBlock::$data = null;
    RecordingFormBlock::$handled = 0;
});

it('accepts a submission to a HasForm form from a page that does not place it', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'content' => []]);
    $cms = app(Cms::class);
    $cms->frontendRoutes();

    $props = $cms->forms()->props('ServiceOrder', $page);

    expect($props)->toBe([
        'action' => route('tbtop-cms.form.submit', ['locale' => 'en', 'page' => $page->getKey(), 'block' => 'ServiceOrder']),
        'honeypotField' => 'website',
    ]);

    $this->post($props['action'], ['phone' => '+380'])->assertRedirect()->assertSessionHasNoErrors();

    $submission = FormSubmission::query()->sole();

    expect($submission->page_id)->toBe($page->getKey())
        ->and($submission->block_id)->toBe('ServiceOrder')
        ->and($submission->kind)->toBe('ServiceOrder')
        ->and(NamedFormBlock::$data)->toBe([]);
});

it('validates a name-addressed submission with the form rules', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published]);
    app(Cms::class)->frontendRoutes();

    $this->post(app(Cms::class)->forms()->action('ServiceOrder', $page), [])->assertSessionHasErrors('phone');

    expect(FormSubmission::query()->count())->toBe(0);
});

it('does not reach a form block without HasForm by its kind', function (): void {
    $page = Page::factory()->create(['status' => PageStatus::Published, 'content' => []]);
    app(Cms::class)->frontendRoutes();

    $this->postJson(route('tbtop-cms.form.submit', ['locale' => 'en', 'page' => $page->getKey(), 'block' => 'Recording']), ['value' => 'x'])
        ->assertNotFound();

    expect(app(Cms::class)->forms()->has('Recording'))->toBeFalse()
        ->and(RecordingFormBlock::$handled)->toBe(0);
});

it('rejects two blocks declaring the same form name', function (): void {
    config()->set('tbtop-cms.blocks.types', [NamedFormBlock::class, NamedFormTwinBlock::class]);

    app(Cms::class)->forms()->all();
})->throws(InvalidArgumentException::class, 'Form [ServiceOrder] is declared by both');
