<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Frontend\Seo\GtmContainerId;
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

it('resolves the container id into the seo props', function (): void {
    app(Settings::class)->set('seo.gtm_container_id', 'GTM-ABC1234');
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.gtmId', 'GTM-ABC1234');
});

it('resolves a null gtmId when the setting is unset', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.gtmId', null);
});

it('extracts the id from a stored full install snippet and drops malformed values', function (): void {
    app(Settings::class)->set('seo.gtm_container_id', "<!-- Google Tag Manager -->\n<script>j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;...('GTM-XYZ987');</script>");
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.gtmId', 'GTM-XYZ987');

    app(Settings::class)->set('seo.gtm_container_id', 'not-a-container-id');

    $this->get('/about', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.seo.gtmId', null);
});

it('extracts and validates container ids', function (): void {
    expect(GtmContainerId::extract('GTM-ABC1234'))->toBe('GTM-ABC1234')
        ->and(GtmContainerId::extract('gtm-abc1234'))->toBe('GTM-ABC1234')
        ->and(GtmContainerId::extract("<script>j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;</script>('GTM-N7K2Q9');"))->toBe('GTM-N7K2Q9')
        ->and(GtmContainerId::extract('  plain  '))->toBe('plain')
        ->and(GtmContainerId::extract(''))->toBe('')
        ->and(GtmContainerId::valid('GTM-ABC1234'))->toBeTrue()
        ->and(GtmContainerId::valid('GTM-'))->toBeFalse()
        ->and(GtmContainerId::valid('plain'))->toBeFalse()
        ->and(GtmContainerId::valid(''))->toBeFalse();
});

it('does not mine a GTM-looking substring out of unrecognized surrounding text', function (): void {
    // Neither a bare anchored id nor a recognized install snippet — returned
    // as-is, so valid() rejects it and no GTM markup is ever rendered.
    expect(GtmContainerId::extract('not-a-container-GTM-ABC123'))->toBe('not-a-container-GTM-ABC123')
        ->and(GtmContainerId::valid(GtmContainerId::extract('not-a-container-GTM-ABC123')))->toBeFalse()
        ->and(GtmContainerId::valid(GtmContainerId::extract('GTM-ABC123<script>')))->toBeFalse();
});
