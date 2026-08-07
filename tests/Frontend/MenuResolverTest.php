<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Menu\Models\Menu;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);
});

function menuPage(array $name): Page
{
    return Page::factory()->create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => $name,
    ]);
}

it('shares menus with per-locale labels and localized page urls', function (): void {
    $page = menuPage(['en' => 'About us', 'uk' => 'Pro nas']);

    Menu::create([
        'name' => 'Main',
        'key' => 'main',
        'items' => [
            ['type' => 'page', 'page_id' => $page->id],
            [
                'type' => 'url',
                'label' => ['en' => 'Blog', 'uk' => 'Блог'],
                'url' => ['en' => '/blog'],
                'target_blank' => true,
            ],
        ],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/about-us', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.cms.menus.main.0.label', 'About us')
        ->assertJsonPath('props.cms.menus.main.0.url', '/about-us')
        ->assertJsonPath('props.cms.menus.main.1.label', 'Blog')
        ->assertJsonPath('props.cms.menus.main.1.url', '/blog')
        ->assertJsonPath('props.cms.menus.main.1.target_blank', true);

    // uk: page label/url localize; the url item falls back to the default locale's url
    $this->get('/uk/pro-nas', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.cms.menus.main.0.label', 'Pro nas')
        ->assertJsonPath('props.cms.menus.main.0.url', '/uk/pro-nas')
        ->assertJsonPath('props.cms.menus.main.1.label', 'Блог')
        ->assertJsonPath('props.cms.menus.main.1.url', '/blog');
});

it('prefers a stored label override to the page name for its locale only', function (): void {
    $page = menuPage(['en' => 'About us', 'uk' => 'Pro nas']);

    Menu::create([
        'name' => 'Main',
        'key' => 'main',
        'items' => [
            ['type' => 'page', 'page_id' => $page->id, 'label' => ['uk' => 'Наша команда']],
        ],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/about-us', ['X-Inertia' => 'true'])
        ->assertJsonPath('props.cms.menus.main.0.label', 'About us');

    $this->get('/uk/pro-nas', ['X-Inertia' => 'true'])
        ->assertJsonPath('props.cms.menus.main.0.label', 'Наша команда');
});

it('drops items whose page is unpublished and resolves anchors and children', function (): void {
    $live = menuPage(['en' => 'Live']);
    $draft = Page::factory()->create([
        'type' => 'page',
        'status' => PageStatus::Draft,
        'name' => ['en' => 'Draft'],
    ]);

    Menu::create([
        'name' => 'Main',
        'key' => 'main',
        'items' => [
            ['type' => 'page', 'page_id' => $draft->id],
            [
                'type' => 'anchor',
                'label' => ['en' => 'Deals'],
                'anchor' => ['en' => 'deals'],
                'children' => [
                    ['type' => 'page', 'page_id' => $live->id],
                ],
            ],
        ],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/live', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonCount(1, 'props.cms.menus.main')
        ->assertJsonPath('props.cms.menus.main.0.type', 'anchor')
        ->assertJsonPath('props.cms.menus.main.0.url', '#deals')
        ->assertJsonPath('props.cms.menus.main.0.children.0.label', 'Live')
        ->assertJsonPath('props.cms.menus.main.0.children.0.url', '/live');
});

it('serves a legacy scalar menu row to every locale', function (): void {
    menuPage(['en' => 'Home']);

    Menu::create([
        'name' => 'Footer',
        'key' => 'footer',
        // pre-translation storage shape: plain strings
        'items' => [['type' => 'url', 'label' => 'Contact', 'url' => '/contact']],
    ]);

    app(Cms::class)->frontendRoutes();

    $this->get('/uk/home', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.cms.menus.footer.0.label', 'Contact')
        ->assertJsonPath('props.cms.menus.footer.0.url', '/contact');
});
