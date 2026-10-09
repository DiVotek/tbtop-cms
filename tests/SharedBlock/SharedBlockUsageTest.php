<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\PageEditHelpers;
use Tbtop\Cms\Tests\Fixtures\SimpleBlock;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [SimpleBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
});

it('counts and lists a shared block by every pointer form, once per container', function (): void {
    $row = static fn (string $id, array $extra): array => ['id' => $id, 'kind' => 'Simple', 'active' => true, 'data' => [], ...$extra];
    $page = static fn (string $name, array $content): Page => Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => $name], 'content' => $content]);

    SharedBlock::query()->forceCreate(['id' => 7, 'name' => 'Seven', 'kind' => 'Simple', 'data' => []]);
    SharedBlock::query()->forceCreate(['id' => 70, 'name' => 'Seventy', 'kind' => 'Simple', 'data' => []]);

    $twice = $page('Twice', [$row('a', ['shared' => 7]), $row('b', ['shared' => '7'])]);
    $string = $page('String', [$row('c', ['shared' => '7'])]);
    $page('Lookalikes', [
        $row('d', ['shared' => '70']),
        $row('e', ['shared' => '7a']),
        $row('f', ['shared' => '']),
        $row('g', ['data' => ['shared' => 7, 'count' => 7]]),
    ]);
    $trashed = $page('Trashed', [$row('h', ['shared' => 7])]);
    $trashed->delete();
    Layout::query()->create(['slot' => Slots::FOOTER, 'content' => [$row('i', ['shared' => 7])]]);

    $structure = json_encode($this->actingAs(PageEditHelpers::admin(), 'web')
        ->get('/admin/shared-blocks', ['X-Inertia' => 'true'])->assertOk()->json('props.structure'));
    preg_match_all('/(?:Placed in \d+ places?|Not placed anywhere)/', (string) $structure, $cardCounts);
    // Cards are ordered by name: Seven, then Seventy.
    expect($cardCounts[0])->toBe(['Placed in 4 places', 'Placed in 1 place']);

    $rows = $this->getJson('/admin/shared-blocks/7/edit/tables/sharedBlockUsage')->assertOk()->json('data.data');
    expect(array_column($rows, 'id'))->toBe(["page:{$twice->id}", "page:{$string->id}", "page:{$trashed->id}", 'layout:' . Slots::FOOTER])
        ->and(array_column($rows, 'container'))->toBe(['page', 'page', 'trashed_page', 'layout']);
});
