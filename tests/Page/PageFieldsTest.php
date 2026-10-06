<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageRevision;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Support\PageRevisionWriter;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\ConcertPageType;
use Tbtop\Cms\Tests\Fixtures\ConcertTicketBlock;
use Tbtop\Cms\Tests\Fixtures\EventPageType;
use Tbtop\Cms\Tests\Fixtures\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [HeroBlock::class, ConcertTicketBlock::class]]);
    PageTypeRegistry::extend(fn (): array => [EventPageType::class, ConcertPageType::class]);
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

it('shows the base type\'s fields and then the subtype\'s on the General tab, prefixed', function (): void {
    $general = PageEditHelpers::formTabs(eventPage()->id)[0]['body'];

    $names = [];
    collectFieldNames($general, $names);
    $typeFields = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'field_')));

    expect($typeFields)->toBe(['field_venue', 'field_capacity', 'field_artist']);
});

it('saves the General tab\'s type fields into `fields`, keeping keys the form does not show', function (): void {
    $page = eventPage(fields: ['left_over' => 'kept']);

    test()->actingAs(PageEditHelpers::admin(), 'web')->post("/admin/pages/{$page->id}/edit/forms/pageEditor", [
        ...PageEditHelpers::baseEditPayload(),
        'type' => 'event',
        'subtype' => 'concert',
        'field_venue' => ['en' => 'Arena', 'uk' => 'Арена'],
        'field_capacity' => 500,
        'field_artist' => 'Okean Elzy',
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
        'field_artist' => 'Okean Elzy',
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
