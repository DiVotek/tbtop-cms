<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

it('writes no Content-Signal until an editor opts in', function (): void {
    expect((string) $this->get('/robots.txt')->getContent())->not->toContain('Content-Signal');
});

it('writes the default signals inside the User-agent group once on', function (): void {
    app(Settings::class)->set('seo.content_signals.enabled', true);

    $body = (string) $this->get('/robots.txt')->getContent();
    $group = substr($body, 0, strpos($body, "\n\n") ?: strlen($body));

    expect($group)->toContain('Content-Signal: search=yes, ai-input=yes, ai-train=no');
});

it('follows the editor choice per signal', function (): void {
    $settings = app(Settings::class);
    $settings->set('seo.content_signals.enabled', true);
    $settings->set('seo.content_signals.ai_input', false);
    $settings->set('seo.content_signals.ai_train', true);

    expect((string) $this->get('/robots.txt')->getContent())
        ->toContain('Content-Signal: search=yes, ai-input=no, ai-train=yes');
});

it('drops the signals while indexing is off', function (): void {
    $settings = app(Settings::class);
    $settings->set('seo.content_signals.enabled', true);
    $settings->set('seo.indexing_enabled', false);

    expect((string) $this->get('/robots.txt')->getContent())->not->toContain('Content-Signal');
});

it('drops a Content-Signal line from the custom rules', function (): void {
    app(Settings::class)->set('seo.robots_extra', 'Content-Signal: ai-train=yes');

    expect((string) $this->get('/robots.txt')->getContent())->not->toContain('ai-train=yes');
});
