<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array', 'tbtop-cms.render.root_view' => 'server-head-test']);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Settings::class)->set('site.name', 'Acme Co');
    app(Cms::class)->frontendRoutes();
});

it('escapes a page title containing a script-closing sequence so it cannot break out of the JSON-LD script tag', function (): void {
    $breaking = '</' . 'script><' . 'script>alert(1)</' . 'script>';

    $parent = Page::factory()->category()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Parent']]);
    $page = Page::factory()->article()->create([
        'status' => PageStatus::Published,
        'parent_id' => $parent->id,
        'name' => ['en' => $breaking],
        // The current-page JSON-LD label cascades title→name (see
        // SeoResolver::jsonLd()) — clear the factory's random default title
        // so the breaking payload in `name` is actually what gets rendered.
        'title' => ['en' => ''],
    ]);

    $html = $this->get($page->pathFor('en'))->assertOk()->getContent();

    // JSON_HEX_TAG replaces every '<' and '>' inside the JSON-LD payload with
    // a JSON backslash-u unicode escape for that codepoint, so the raw
    // breaking sequence must not survive into the response, while the
    // escaped '<' does — proving the name round-tripped into the payload
    // rather than being silently dropped.
    $hexEscapedLt = sprintf('\u%04X', ord('<'));

    expect($html)->not->toContain($breaking)
        ->toContain($hexEscapedLt);
});
