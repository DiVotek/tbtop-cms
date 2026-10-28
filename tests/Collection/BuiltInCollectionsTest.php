<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Collection\Models\Entry;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\SiteContacts;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

/** @param  array<string, mixed>  $data */
function builtIn(string $collection, array $data, int $sort = 0, string $status = Entry::PUBLISHED): Entry
{
    return Entry::query()->create(['collection' => $collection, 'status' => $status, 'sort_order' => $sort, 'data' => $data]);
}

it('reads phones and emails from the main office and socials from the social links', function (): void {
    builtIn('offices', ['name' => ['en' => 'Kyiv'], 'phones' => [['number' => '+380 44 1'], ['number' => '+380 44 2']], 'emails' => [['address' => 'kyiv@example.com']]]);
    builtIn('offices', ['name' => ['en' => 'Lviv'], 'phones' => [['number' => '+380 32 1']]], 1);
    builtIn('socials', ['label' => 'TG', 'name' => 'Telegram', 'href' => 'https://t.me/acme']);
    builtIn('socials', ['label' => 'FB', 'href' => 'https://facebook.com/acme'], 1, Entry::DRAFT);

    $contacts = app(SiteContacts::class);

    expect($contacts->phones())->toBe(['+380 44 1', '+380 44 2'])
        ->and($contacts->emails())->toBe(['kyiv@example.com'])
        ->and($contacts->socials())->toBe([['label' => 'TG', 'name' => 'Telegram', 'href' => 'https://t.me/acme']])
        ->and(array_column($contacts->offices(), 'name'))->toBe(['Kyiv', 'Lviv']);
});

it('is empty with no entries', function (): void {
    $contacts = app(SiteContacts::class);

    expect($contacts->phones())->toBe([])->and($contacts->emails())->toBe([])->and($contacts->socials())->toBe([]);
});

it('still shares the contacts under their old settings keys for templates', function (): void {
    builtIn('offices', ['name' => ['en' => 'Kyiv'], 'phones' => [['number' => '+380 44 1']], 'emails' => [['address' => 'hi@example.com']]]);
    builtIn('socials', ['label' => 'TG', 'href' => 'https://t.me/acme']);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    app(Cms::class)->frontendRoutes();

    $settings = $this->get('/about', ['X-Inertia' => 'true'])->assertOk()->json('props.cms.settings');

    expect($settings['site.contacts.phones'])->toBe([['number' => '+380 44 1']])
        ->and($settings['site.contacts.emails'])->toBe([['address' => 'hi@example.com']])
        ->and($settings['site.contacts.socials'])->toBe([['label' => 'TG', 'name' => null, 'href' => 'https://t.me/acme']]);
});
