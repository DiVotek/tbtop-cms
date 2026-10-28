<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\StructureWalk;
use Tbtop\Cms\Admin\Collections\CollectionTable;
use Tbtop\Cms\Collection\CollectionRegistry;
use Tbtop\Cms\Collection\Models\Entry;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\ReviewsCollection;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
    $this->actingAs(PageEditHelpers::admin(), 'web');
});

function adminSocial(string $label, string $href = 'https://t.me/acme', string $status = Entry::PUBLISHED, int $sort = 0): Entry
{
    return Entry::query()->create(['collection' => 'socials', 'status' => $status, 'sort_order' => $sort, 'data' => ['label' => $label, 'name' => $label . ' name', 'href' => $href]]);
}

it('lists every collection as a tab of one page, with one menu item', function (): void {
    adminSocial('TG');
    adminSocial('FB', status: Entry::DRAFT, sort: 1);

    $response = $this->get('/admin/collections', ['X-Inertia' => 'true'])->assertOk();
    $json = json_encode($response->json('props'), JSON_UNESCAPED_SLASHES);

    expect($json)->toContain('"href":"/admin/collections"')
        ->not->toContain('/admin/collections/socials"')
        ->and(substr_count($json, '"label":"' . __('tbtop-cms::nav.items.collections') . '"'))->toBeGreaterThanOrEqual(1);
    foreach (['entries_phones', 'entries_emails', 'entries_socials', 'entryCreate_socials'] as $name) {
        expect($json)->toContain('"' . $name . '"');
    }

    $rows = $this->getJson('/admin/collections/tables/entries_socials')->assertOk()->json('data.data');
    expect(array_column($rows, 'title'))->toBe(['TG name', 'FB name'])
        ->and(array_column($rows, 'status'))->toBe([Entry::PUBLISHED, Entry::DRAFT]);
});

it('opens the tab named in the URL', function (): void {
    $tabs = $this->get('/admin/collections?tab[collections]=socials', ['X-Inertia' => 'true'])->assertOk()->json('props.structure.options.tabs');

    expect(array_column($tabs, 'name'))->toBe(['phones', 'emails', 'socials'])
        ->and(array_column(array_filter($tabs, static fn (array $tab): bool => $tab['active'] ?? false), 'name'))->toBe(['socials'])
        ->and(CollectionTable::listUrl('socials'))->toEndWith('/admin/collections?tab%5Bcollections%5D=socials');
});

it('answers 404 for an entry of a collection that is not registered', function (): void {
    $this->get('/admin/collections/ghost/1/edit', ['X-Inertia' => 'true'])->assertNotFound();
});

it('creates an entry from the modal and sends the editor to its edit page', function (): void {
    $response = $this->postJson('/admin/collections/actions/entryCreateSave_socials', [
        'payload' => ['form' => ['label' => 'TG', 'name' => 'Telegram', 'href' => 'https://t.me/acme', 'status' => 'published']],
    ])->assertOk();

    $entry = Entry::query()->sole();
    expect($entry->collection)->toBe('socials')
        ->and($entry->data)->toEqual(['name' => 'Telegram', 'label' => 'TG', 'href' => 'https://t.me/acme'])
        ->and($entry->isPublished())->toBeTrue()
        ->and(collect($response->json('effects'))->firstWhere('kind', 'redirect')['href'] ?? '')->toContain("/admin/collections/socials/{$entry->id}/edit");
});

it('rejects an entry the collection form does not accept', function (): void {
    $this->postJson('/admin/collections/actions/entryCreateSave_socials', [
        'payload' => ['form' => ['label' => 'TG', 'href' => 'not a url', 'status' => 'published']],
    ])->assertUnprocessable();

    expect(Entry::query()->count())->toBe(0);
});

it('edits an entry and refuses a save from a stale form', function (): void {
    $entry = adminSocial('TG');
    $url = "/admin/collections/socials/{$entry->id}/edit";
    $formA = $this->get($url, ['X-Inertia' => 'true'])->assertOk()->json('props.data.entryEditor');
    $formB = $this->get($url, ['X-Inertia' => 'true'])->assertOk()->json('props.data.entryEditor');
    expect($formA['label'])->toBe('TG')->and($formA['status'])->toBe(Entry::PUBLISHED);

    $this->post("{$url}/forms/entryEditor", [...$formA, 'label' => 'TGA', 'status' => Entry::DRAFT])->assertRedirect()->assertSessionHasNoErrors();
    $this->post("{$url}/forms/entryEditor", [...$formB, 'label' => 'TGB'])->assertRedirect();

    $entry->refresh();
    expect($entry->data['label'])->toBe('TGA')->and($entry->status)->toBe(Entry::DRAFT);
    $notify = collect(session('inertia.flash_data')['tbtop.effects'] ?? [])->firstWhere('kind', 'notify');
    expect($notify['message'] ?? null)->toBe(__('tbtop-cms::collections.conflict'));
});

it('does not open an entry under another collection', function (): void {
    $entry = adminSocial('TG');

    $this->get("/admin/collections/phones/{$entry->id}/edit", ['X-Inertia' => 'true'])->assertNotFound();
});

it('deletes an entry from its row', function (): void {
    config(['tbtop-cms.collections' => [ReviewsCollection::class]]);
    app()->instance(CollectionRegistry::class, new CollectionRegistry);
    $entry = Entry::query()->create(['collection' => 'review', 'data' => ['author' => 'A']]);

    $this->postJson('/admin/collections/actions/entries_reviewDelete', ['payload' => ['row' => ['id' => $entry->id]]])->assertOk();

    expect(Entry::query()->count())->toBe(0);
});

it('narrows an embedded table to the entries related to a page', function (): void {
    config(['tbtop-cms.collections' => [ReviewsCollection::class]]);
    app()->instance(CollectionRegistry::class, new CollectionRegistry);
    $service = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Audit']]);
    Entry::query()->create(['collection' => 'review', 'data' => ['author' => 'A', 'services' => [(string) $service->id]]]);
    Entry::query()->create(['collection' => 'review', 'data' => ['author' => 'B', 'services' => ['999']]]);

    $table = CollectionTable::make(new S, 'review')->relatedTo($service)->build();
    $rows = ($table->queryClosure())()->get();

    expect($rows->pluck('title')->all())->toBe(['A']);
});

it('deletes from an embedded table only the entries it shows', function (): void {
    config(['tbtop-cms.collections' => [ReviewsCollection::class]]);
    app()->instance(CollectionRegistry::class, new CollectionRegistry);
    $service = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Audit']]);
    $related = Entry::query()->create(['collection' => 'review', 'data' => ['author' => 'A', 'services' => [(string) $service->id]]]);
    $other = Entry::query()->create(['collection' => 'review', 'data' => ['author' => 'B', 'services' => ['999']]]);

    $table = CollectionTable::make(new S, 'review')->relatedTo($service)->build();
    $delete = collect(StructureWalk::actionSearchDescendants($table->toNode()))->first(static fn (mixed $node): bool => $node instanceof ActionBuilder && str_ends_with($node->name, 'Delete'));
    $handle = $delete->handler();
    $handle(new ActionCtx(request(), null, row: ['id' => $other->id]));
    $handle(new ActionCtx(request(), null, row: ['id' => $related->id]));

    expect(Entry::query()->pluck('id')->all())->toBe([$other->id]);
});

it('gates the embedded delete like the collection page when roles are on', function (bool $allowed): void {
    config(['tbtop-cms.roles' => true, 'tbtop-cms.collections' => [ReviewsCollection::class]]);
    app()->instance(CollectionRegistry::class, new CollectionRegistry);
    Gate::define('tbtop-cms.pages', static fn (): bool => $allowed);

    $table = CollectionTable::make(new S, 'review')->relatedTo(1)->build();
    $delete = collect(StructureWalk::actionSearchDescendants($table->toNode()))->first(static fn (mixed $node): bool => $node instanceof ActionBuilder && str_ends_with($node->name, 'Delete'));

    // A failing gate drops the action from the wire; the action endpoint re-runs the same check.
    expect($delete !== null)->toBe($allowed);
})->with(['allowed' => [true], 'denied' => [false]]);

it('finds entries for the collection field picker, by title and by id', function (): void {
    $tg = adminSocial('TG');
    $fb = adminSocial('FB', status: Entry::DRAFT, sort: 1);

    $found = $this->postJson('/admin/entry-search', ['collection' => 'socials', 'search' => 'fb'])->assertOk()->json('options');
    $byIds = $this->postJson('/admin/entry-search', ['collection' => 'socials', 'ids' => [(string) $fb->id, (string) $tg->id]])->assertOk()->json('options');

    expect($found)->toBe([['value' => (string) $fb->id, 'label' => 'FB name', 'draft' => true]])
        ->and(array_column($byIds, 'label'))->toBe(['FB name', 'TG name'])
        ->and($this->postJson('/admin/entry-search', ['collection' => 'ghost'])->json('options'))->toBe([]);
});
