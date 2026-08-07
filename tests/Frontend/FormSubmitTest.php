<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Block\Blocks\HeroBlock;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\SubmissionStatus;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Mail\ContactFormMail;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\RecordingFormBlock;
use Tbtop\Cms\Tests\Fixtures\ThrowingFormBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array', 'session.driver' => 'array']);
    config()->set('tbtop-cms.blocks.types', [ContactForm::class, HeroBlock::class]);
    config()->set('tbtop-cms.forms.throttle', '3,1');
    Language::factory()->default()->create(['locale' => 'en']);
    Mail::fake();
    app(Cms::class)->frontendRoutes();
    app(Settings::class)->set('notifications.mail.enabled', true);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');
});

function contactFormPage(): Page
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

function submitUrl(Page $page, string $block, string $locale = 'en'): string
{
    return route('tbtop-cms.form.submit', ['locale' => $locale, 'page' => $page->getKey(), 'block' => $block]);
}

it('exposes the submit action url in the rendered form block props', function (): void {
    $page = contactFormPage();

    $this->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.form-1.type', 'ContactForm')
        ->assertJsonPath('props.render.elements.form-1.props.action', submitUrl($page, 'form-1'));
});

it('handles a valid submission and mails the recipient', function (): void {
    $page = contactFormPage();

    $this->post(submitUrl($page, 'form-1'), [
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'message' => 'Hello there',
    ])->assertRedirect();

    Mail::assertSent(ContactFormMail::class, fn (ContactFormMail $mail): bool => $mail->hasTo('ops@example.test'));
});

it('rejects a submission that fails server-side validation', function (): void {
    $page = contactFormPage();

    $this->postJson(submitUrl($page, 'form-1'), ['name' => '', 'email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'message']);

    Mail::assertNothingSent();
    expect(FormSubmission::query()->count())->toBe(0);
});

it('persists a handled submission after a successful submit', function (): void {
    $page = contactFormPage();

    $this->post(submitUrl($page, 'form-1'), [
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'message' => 'Hello there',
    ])->assertRedirect();

    $submission = FormSubmission::query()->sole();

    expect($submission->status)->toBe(SubmissionStatus::Handled)
        ->and($submission->handled_at)->not->toBeNull()
        ->and($submission->error)->toBeNull()
        ->and($submission->page_id)->toBe($page->getKey())
        ->and($submission->block_id)->toBe('form-1')
        ->and($submission->kind)->toBe('ContactForm')
        ->and($submission->payload)->toBe(['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hello there']);
});

it('persists a failed submission when the block handler throws', function (): void {
    config()->set('tbtop-cms.blocks.types', [ThrowingFormBlock::class]);

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Boom'],
        'content' => [[
            'id' => 'throw-1',
            'kind' => 'Throwing',
            'active' => true,
            'data' => [],
        ]],
    ]);

    $this->post(submitUrl($page, 'throw-1'), ['value' => 'x'])
        ->assertRedirect()
        ->assertSessionHasErrors(['form']);

    $submission = FormSubmission::query()->sole();

    expect($submission->status)->toBe(SubmissionStatus::Failed)
        ->and($submission->error)->toBe('handler boom')
        ->and($submission->handled_at)->toBeNull();
});

it('does not persist a submission when forms.store is disabled', function (): void {
    config()->set('tbtop-cms.forms.store', false);
    $page = contactFormPage();

    $this->post(submitUrl($page, 'form-1'), [
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'message' => 'Hello there',
    ])->assertRedirect();

    expect(FormSubmission::query()->count())->toBe(0);
});

it('returns 404 when submitting to an unknown block', function (): void {
    $page = contactFormPage();

    $this->postJson(submitUrl($page, 'nope'))->assertNotFound();
});

it('rejects a non-numeric page route segment before it reaches the controller', function (): void {
    $this->postJson('/_cms/forms/en/not-a-number/form-1')->assertStatus(405);
});

it('returns 404 when submitting to a block that is not a form', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Plain'],
        'content' => [[
            'id' => 'hero-1',
            'kind' => 'Hero',
            'active' => true,
            'data' => ['title' => ['en' => 'Hi']],
        ]],
    ]);

    $this->postJson(submitUrl($page, 'hero-1'))->assertNotFound();
});

it('returns 404 when submitting to a draft page', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Draft,
        'name' => ['en' => 'Hidden'],
        'content' => [[
            'id' => 'form-1',
            'kind' => 'ContactForm',
            'active' => true,
            'data' => ['recipient' => 'ops@example.test'],
        ]],
    ]);

    $this->postJson(submitUrl($page, 'form-1'))->assertNotFound();
    Mail::assertNothingSent();
});

it('throttles repeated submissions from the same client', function (): void {
    $page = contactFormPage();
    $url = submitUrl($page, 'form-1');

    for ($i = 0; $i < 3; $i++) {
        $this->postJson($url, [])->assertStatus(422);
    }

    $this->postJson($url, [])->assertStatus(429);
});

it('processes a submission under the locale carried by the action url', function (): void {
    Language::factory()->create([
        'locale' => 'uk',
        'is_admin_active' => true,
        'is_frontend_active' => true,
    ]);
    config()->set('tbtop-cms.blocks.types', [RecordingFormBlock::class]);
    RecordingFormBlock::$locale = null;

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Recorder'],
        'content' => [[
            'id' => 'rec-1',
            'kind' => 'Recording',
            'active' => true,
            'data' => ['label' => ['en' => 'EN copy', 'uk' => 'UA copy']],
        ]],
    ]);

    $this->post(submitUrl($page, 'rec-1', 'uk'), ['value' => 'hello'])->assertRedirect();

    expect(RecordingFormBlock::$locale)->toBe('uk')
        ->and(RecordingFormBlock::$data['label'])->toBe('UA copy');
});

it('does not discard a non-translatable form field coincidentally shaped like a locale map', function (): void {
    config()->set('tbtop-cms.blocks.types', [RecordingFormBlock::class]);
    RecordingFormBlock::$data = [];

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Recorder'],
        'content' => [[
            'id' => 'rec-1',
            'kind' => 'Recording',
            'active' => true,
            'data' => [
                'label' => ['en' => 'EN copy'],
                'config' => ['en' => 'not-a-translation', 'url' => '/about'],
            ],
        ]],
    ]);

    $this->post(submitUrl($page, 'rec-1'), ['value' => 'hello'])->assertRedirect();

    expect(RecordingFormBlock::$data['config'])->toBe(['en' => 'not-a-translation', 'url' => '/about']);
});

it('gracefully handles a submission whose block handler throws', function (): void {
    config()->set('tbtop-cms.blocks.types', [ThrowingFormBlock::class]);

    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Boom'],
        'content' => [[
            'id' => 'throw-1',
            'kind' => 'Throwing',
            'active' => true,
            'data' => [],
        ]],
    ]);

    $this->post(submitUrl($page, 'throw-1'), ['value' => 'x'])
        ->assertRedirect()
        ->assertSessionHasErrors(['form']);
});

it('returns 404 when the block id exists only on a different page', function (): void {
    $formPage = contactFormPage();
    $otherPage = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Other'],
        'content' => [],
    ]);

    $this->postJson(submitUrl($otherPage, 'form-1'), [
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'message' => 'Hello there',
    ])->assertNotFound();

    Mail::assertNothingSent();
});

it('returns 404 when submitting to an inactive block', function (): void {
    $page = Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Contact'],
        'content' => [[
            'id' => 'form-1',
            'kind' => 'ContactForm',
            'active' => false,
            'data' => ['recipient' => 'ops@example.test'],
        ]],
    ]);

    $this->postJson(submitUrl($page, 'form-1'))->assertNotFound();
    Mail::assertNothingSent();
});

it('exposes the configured honeypot field name in the rendered form block props', function (): void {
    $page = contactFormPage();

    $this->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.render.elements.form-1.props.honeypotField', 'website');
});

it('omits the honeypot prop from the payload when disabled via config', function (): void {
    config()->set('tbtop-cms.forms.honeypot_field', false);
    $page = contactFormPage();

    $response = $this->get('/' . $page->pathFor('en'), ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.render.elements.form-1.props'))->not->toHaveKey('honeypotField');
});

it('silently drops a submission when the honeypot field is filled', function (): void {
    $page = contactFormPage();

    $response = $this->post(submitUrl($page, 'form-1'), [
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'message' => 'Hello there',
        'website' => 'https://spam.example.test',
    ])->assertRedirect();

    expect($response->getSession()->get('errors'))->toBeNull();
    Mail::assertNothingSent();
    expect(FormSubmission::query()->count())->toBe(0);
});

it('still persists and delivers a submission when the honeypot field is left empty', function (): void {
    $page = contactFormPage();

    $this->post(submitUrl($page, 'form-1'), [
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'message' => 'Hello there',
        'website' => '',
    ])->assertRedirect();

    Mail::assertSent(ContactFormMail::class, fn (ContactFormMail $mail): bool => $mail->hasTo('ops@example.test'));
    expect(FormSubmission::query()->count())->toBe(1);
});

it('bypasses the honeypot check entirely when disabled via config', function (): void {
    config()->set('tbtop-cms.forms.honeypot_field', false);
    $page = contactFormPage();

    $this->post(submitUrl($page, 'form-1'), [
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'message' => 'Hello there',
        'website' => 'https://spam.example.test',
    ])->assertRedirect();

    Mail::assertSent(ContactFormMail::class);
    expect(FormSubmission::query()->count())->toBe(1);
});
