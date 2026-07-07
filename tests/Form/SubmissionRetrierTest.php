<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\SubmissionRetrier;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('tbtop-cms.blocks.types', [ContactForm::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function contactPageForRetry(): Page
{
    return Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Contact'],
        'content' => [[
            'id' => 'form-1',
            'kind' => 'ContactForm',
            'active' => true,
            'data' => ['recipient' => 'ops@example.test'],
        ]],
    ]);
}

it('marks a failed submission handled once the failure cause is gone', function (): void {
    $page = contactPageForRetry();

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'],
        'status' => SubmissionStatus::Failed,
        'error' => 'mail server unreachable',
    ]);

    app(SubmissionRetrier::class)->retry($submission);

    $submission->refresh();

    expect($submission->status)->toBe(SubmissionStatus::Handled)
        ->and($submission->error)->toBeNull()
        ->and($submission->handled_at)->not->toBeNull();
});

it('keeps a submission failed with an error when the block was removed from the page', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Contact'],
        'content' => [],
    ]);

    $submission = FormSubmission::query()->create([
        'page_id' => $page->getKey(),
        'block_id' => 'form-1',
        'kind' => 'ContactForm',
        'locale' => 'en',
        'payload' => ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'],
        'status' => SubmissionStatus::Failed,
        'error' => 'mail server unreachable',
    ]);

    app(SubmissionRetrier::class)->retry($submission);

    $submission->refresh();

    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->not->toBeNull()
        ->and($submission->handled_at)->toBeNull();
});
