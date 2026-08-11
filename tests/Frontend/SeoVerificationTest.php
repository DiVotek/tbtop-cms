<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Frontend\Seo\VerificationCode;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

it('resolves verification meta entries only for non-empty codes', function (): void {
    app(Settings::class)->set('seo.verification.google', 'g-code-123');
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.verification', [
            ['key' => 'google', 'name' => 'google-site-verification', 'content' => 'g-code-123'],
        ]);
});

it('resolves no verification entries when none of the codes are set', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.verification', []);
});

it('normalizes a pasted full meta tag down to just its content value', function (): void {
    app(Settings::class)->set('seo.verification.bing', '<meta name="msvalidate.01" content="bing-code-456" />');
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.verification.0.content', 'bing-code-456');
});

it('extracts the content attribute from a pasted verification meta tag', function (): void {
    expect(VerificationCode::extract('<meta name="google-site-verification" content="abc123">'))->toBe('abc123')
        ->and(VerificationCode::extract("<meta name='yandex-verification' content='xyz789' />"))->toBe('xyz789')
        ->and(VerificationCode::extract('plain-code'))->toBe('plain-code')
        ->and(VerificationCode::extract('  plain-code  '))->toBe('plain-code')
        ->and(VerificationCode::extract(''))->toBe('');
});
