<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Block\Blocks\HeroBlock;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Mail\ContactFormMail;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    config()->set('tbtop-cms.blocks.types', [ContactForm::class, HeroBlock::class]);
    config()->set('tbtop-cms.forms.throttle', '3,1');
    Language::factory()->default()->create(['locale' => 'en']);
    Mail::fake();
    app(Cms::class)->frontendRoutes();
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

function submitUrl(Page $page, string $block): string
{
    return route('tbtop-cms.form.submit', ['page' => $page->getKey(), 'block' => $block]);
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
});

it('returns 404 when submitting to an unknown block', function (): void {
    $page = contactFormPage();

    $this->postJson(submitUrl($page, 'nope'))->assertNotFound();
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
