<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Admin\Dsl\S;
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

it('lists each collection in the menu and shows its entries', function (): void {
    adminSocial('TG');
    adminSocial('FB', status: Entry::DRAFT, sort: 1);

    $response = $this->get('/admin/collections/socials', ['X-Inertia' => 'true'])->assertOk();
    $json = json_encode($response->json('props'), JSON_UNESCAPED_SLASHES);

    expect($json)->toContain('/admin/collections/offices')->toContain('/admin/collections/socials')
        ->and($response->json('props.title') ?? $json)->toContain('Social links');

    $rows = $this->getJson('/admin/collections/socials/tables/entries_socials')->assertOk()->json('data.data');
    expect(array_column($rows, 'title'))->toBe(['TG name', 'FB name'])
        ->and(array_column($rows, 'status'))->toBe([Entry::PUBLISHED, Entry::DRAFT]);
});

it('answers 404 for a collection that is not registered', function (): void {
    $this->get('/admin/collections/ghost', ['X-Inertia' => 'true'])->assertNotFound();
});

it('creates an entry from the modal and sends the editor to its edit page', function (): void {
    $response = $this->postJson('/admin/collections/socials/actions/entryCreateSave', [
        'payload' => ['form' => ['label' => 'TG', 'name' => 'Telegram', 'href' => 'https://t.me/acme', 'status' => 'published']],
    ])->assertOk();

    $entry = Entry::query()->sole();
    expect($entry->collection)->toBe('socials')
        ->and($entry->data)->toBe(['name' => 'Telegram', 'label' => 'TG', 'href' => 'https://t.me/acme'])
        ->and($entry->isPublished())->toBeTrue()
        ->and(collect($response->json('effects'))->firstWhere('kind', 'redirect')['href'] ?? '')->toContain("/admin/collections/socials/{$entry->id}/edit");
});

it('rejects an entry the collection form does not accept', function (): void {
    $this->postJson('/admin/collections/socials/actions/entryCreateSave', [
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

    $this->get("/admin/collections/offices/{$entry->id}/edit", ['X-Inertia' => 'true'])->assertNotFound();
});

it('deletes an entry from its row', function (): void {
    $entry = adminSocial('TG');

    $this->postJson('/admin/collections/socials/actions/entries_socialsDelete', ['payload' => ['row' => ['id' => $entry->id]]])->assertOk();

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

it('finds entries for the collection field picker, by title and by id', function (): void {
    $tg = adminSocial('TG');
    $fb = adminSocial('FB', status: Entry::DRAFT, sort: 1);

    $found = $this->postJson('/admin/entry-search', ['collection' => 'socials', 'search' => 'fb'])->assertOk()->json('options');
    $byIds = $this->postJson('/admin/entry-search', ['collection' => 'socials', 'ids' => [(string) $fb->id, (string) $tg->id]])->assertOk()->json('options');

    expect($found)->toBe([['value' => (string) $fb->id, 'label' => 'FB name', 'draft' => true]])
        ->and(array_column($byIds, 'label'))->toBe(['FB name', 'TG name'])
        ->and($this->postJson('/admin/entry-search', ['collection' => 'ghost'])->json('options'))->toBe([]);
});
