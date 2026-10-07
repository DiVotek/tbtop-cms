<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageRevision;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Support\PageRevisionWriter;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\BoxOfficePageType;
use Tbtop\Cms\Tests\Fixtures\ConcertPageType;
use Tbtop\Cms\Tests\Fixtures\ConcertTicketBlock;
use Tbtop\Cms\Tests\Fixtures\EventPageType;
use Tbtop\Cms\Tests\Fixtures\FestivalPageType;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [HeroBlock::class, ConcertTicketBlock::class]]);
    PageTypeRegistry::extend(fn (): array => [EventPageType::class, ConcertPageType::class, FestivalPageType::class, BoxOfficePageType::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);
    app(Cms::class)->frontendRoutes();
});

/**
 * @param  array<string, mixed>  $fields
 * @param  list<array<string, mixed>>  $content
 */
function eventPage(?string $subtype = 'concert', array $fields = [], array $content = []): Page
{
    return Page::create([
        'type' => 'event', 'subtype' => $subtype, 'status' => PageStatus::Published,
        'name' => ['en' => 'Gig'], 'fields' => $fields, 'content' => $content,
    ]);
}

/** @return array<string, mixed> */
function siteProps(Page $page, string $locale = 'en'): array
{
    $path = $locale === 'en' ? '/' . $page->pathFor('en') : "/{$locale}/" . $page->pathFor($locale);

    return test()->get($path, ['X-Inertia' => 'true'])->assertOk()->json('props');
}

/** @return list<string> action names on the "+ Add block" tab, for a signed-in admin */
function addBlockOffer(Page $page): array
{
    $structure = test()->get("/admin/pages/{$page->id}/edit", ['X-Inertia' => 'true'])->assertOk()->json('props.structure');
    $tabs = findNodeByKind(findNodeByKind($structure, 'form'), 'tabs')['options']['tabs'];
    $actions = [];
    collectNodesByKind(collect($tabs)->firstWhere('label', __('tbtop-cms::pages.tabs_edit.add_block'))['body'], 'action', $actions);

    return array_column($actions, 'name');
}

it('shows the base type\'s fields, then each subtype\'s section shown only while that subtype is picked', function (): void {
    $general = PageEditHelpers::formTabs(eventPage()->id)[0]['body'];

    $names = [];
    collectFieldNames($general, $names);
    $sections = [];
    collectNodesByKind($general, 'section', $sections);
    $hiddenIf = [];
    foreach ($sections as $section) {
        $hiddenIf[$section['options']['title']] = $section['meta']['hiddenIf'] ?? null;
    }

    expect(array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'field_'))))
        ->toBe(['field_venue', 'field_capacity', 'field_concert__artist', 'field_festival__headliner', 'field_box_office__capacity'])
        ->and($hiddenIf)->toMatchArray([
            'Event' => null,
            'Concert' => ['op' => 'neq', 'field' => 'subtype', 'value' => 'concert'],
            'Festival' => ['op' => 'neq', 'field' => 'subtype', 'value' => 'festival'],
        ]);
});

/** @param  array<string, mixed>  $form */
function saveEventPage(Page $page, array $form): TestResponse
{
    if (! auth('web')->check()) {
        test()->actingAs(PageEditHelpers::admin(), 'web');
    }

    return test()->post("/admin/pages/{$page->id}/edit/forms/pageEditor", [
        ...PageEditHelpers::baseEditPayload(),
        'type' => 'event',
        'field_venue' => ['en' => 'Arena'],
        ...$form,
    ]);
}

it('does not let a required field of a subtype that is not picked block the save', function (): void {
    $page = eventPage(fields: ['artist' => 'Okean Elzy']);

    saveEventPage($page, ['subtype' => 'concert', 'field_concert__artist' => 'Muse', 'field_festival__headliner' => ''])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($page->refresh()->fields['artist'])->toBe('Muse');
});

it('requires a required field of the subtype being picked', function (): void {
    $page = eventPage(fields: ['artist' => 'Okean Elzy']);

    saveEventPage($page, ['subtype' => 'festival', 'field_festival__headliner' => ''])
        ->assertSessionHasErrors('field_festival__headliner');

    expect($page->refresh()->subtype)->toBe('concert');
});

it('switches the subtype and saves its fields in one save, ignoring the sections of the subtypes not picked', function (): void {
    $page = eventPage(fields: ['artist' => 'Okean Elzy']);

    saveEventPage($page, ['subtype' => 'festival', 'field_concert__artist' => 'Stale', 'field_festival__headliner' => 'Muse'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($page->refresh()->subtype)->toBe('festival')
        ->and($page->fields)->toMatchArray(['headliner' => 'Muse', 'artist' => 'Okean Elzy']);
});

it('lets a subtype that re-declares a base field own it: the base input yields, the subtype\'s rule decides, one value ships', function (): void {
    $page = eventPage('box_office', ['capacity' => 'Sold out']);
    $general = PageEditHelpers::formTabs($page->id)[0]['body'];

    expect(findFieldByName($general, 'field_capacity')['meta']['hiddenIf'] ?? null)
        ->toBe(['op' => 'in', 'field' => 'subtype', 'value' => ['box_office']]);

    // The base input still carries the stored text, which its own `integer` rule would reject.
    saveEventPage($page, ['subtype' => 'box_office', 'field_capacity' => 'Sold out', 'field_box_office__capacity' => ''])
        ->assertSessionHasErrors('field_box_office__capacity')
        ->assertSessionDoesntHaveErrors('field_capacity');

    saveEventPage($page, ['subtype' => 'box_office', 'field_capacity' => 'Sold out', 'field_box_office__capacity' => 'Few left'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($page->refresh()->fields)->toBe(['capacity' => 'Few left', 'venue' => ['en' => 'Arena']])
        ->and(siteProps($page)['page']['fields'])->toBe(['venue' => 'Arena', 'capacity' => 'Few left']);

    saveEventPage($page, ['subtype' => 'concert', 'field_capacity' => 'Sold out'])
        ->assertSessionHasErrors('field_capacity');
});

it('saves the General tab\'s type fields into `fields`, keeping keys the form does not show', function (): void {
    $page = eventPage(fields: ['left_over' => 'kept']);

    test()->actingAs(PageEditHelpers::admin(), 'web')->post("/admin/pages/{$page->id}/edit/forms/pageEditor", [
        ...PageEditHelpers::baseEditPayload(),
        'type' => 'event',
        'subtype' => 'concert',
        'field_venue' => ['en' => 'Arena', 'uk' => 'Арена'],
        'field_capacity' => 500,
        'field_concert__artist' => 'Okean Elzy',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($page->refresh()->fields)->toEqual([
        'left_over' => 'kept',
        'venue' => ['en' => 'Arena', 'uk' => 'Арена'],
        'capacity' => 500,
        'artist' => 'Okean Elzy',
    ]);
});

it('ships base and subtype fields as page.fields, flattened to the locale, computed ones included, and readable from a block', function (): void {
    $page = eventPage(
        fields: ['venue' => ['en' => 'Arena', 'uk' => 'Арена'], 'capacity' => '500', 'artist' => 'Okean Elzy'],
        content: [['id' => 'ticket', 'kind' => 'ConcertTicket', 'active' => true, 'sort' => 0, 'data' => ['price' => '20']]],
    );
    $page->setTranslation('name', 'uk', 'Концерт')->save();

    $props = siteProps($page);
    $uk = siteProps($page, 'uk');

    expect($props['page']['fields'])->toBe(['venue' => 'Arena', 'capacity' => 500, 'artist' => 'Okean Elzy', 'billing' => 'OKEAN ELZY LIVE'])
        ->and($props['page']['type'])->toBe('event')
        ->and($props['page']['subtype'])->toBe('concert')
        ->and($uk['page']['fields']['venue'])->toBe('Арена')
        ->and($props['render']['elements']['ticket']['props'])->toBe(['price' => '20', 'artist' => 'Okean Elzy']);
});

it('keeps `fields` when the subtype changes, and stops resolving what the page no longer declares', function (): void {
    $page = eventPage(fields: ['venue' => ['en' => 'Arena'], 'artist' => 'Okean Elzy']);

    test()->actingAs(PageEditHelpers::admin(), 'web')->post("/admin/pages/{$page->id}/edit/forms/pageEditor", [
        ...PageEditHelpers::baseEditPayload(),
        'type' => 'event',
        'subtype' => '',
        'field_venue' => ['en' => 'Arena'],
        'field_concert__artist' => 'Okean Elzy',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($page->refresh()->subtype)->toBeNull()
        ->and($page->fields)->toMatchArray(['artist' => 'Okean Elzy'])
        ->and(siteProps($page)['page']['fields'])->toBe(['venue' => 'Arena', 'capacity' => null]);
});

it('offers a block placed on a subtype key in "+ Add block" on that subtype only', function (): void {
    test()->actingAs(PageEditHelpers::admin(), 'web');

    expect(addBlockOffer(eventPage()))->toContain('pageBlockAdd_ConcertTicket')
        ->and(addBlockOffer(eventPage(subtype: null)))->not->toContain('pageBlockAdd_ConcertTicket')
        ->toContain('pageBlockAdd_Hero');
});

it('snapshots `fields` in a revision and restores it', function (): void {
    $page = eventPage(fields: ['artist' => 'First']);
    $page->fields = ['artist' => 'Second'];
    $page->save();

    $first = PageRevision::query()->where('page_id', $page->id)->orderBy('id')->firstOrFail();
    expect($first->payload['fields'])->toBe(['artist' => 'First']);

    app(PageRevisionWriter::class)->restore($page, $first);

    expect($page->refresh()->fields)->toBe(['artist' => 'First']);
});
