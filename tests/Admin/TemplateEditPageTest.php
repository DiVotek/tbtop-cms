<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Template\Models\Template;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);

    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);
    $this->actingAs($user, 'web');
});

it('titles the create page from translations', function (): void {
    $response = test()->get('/admin/templates/create', ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::templates.create_title'));
});

it('titles the edit page from translations', function (): void {
    $template = Template::create(['name' => 'Landing', 'content' => []]);

    $response = test()->get("/admin/templates/{$template->id}/edit", ['X-Inertia' => 'true'])->assertOk();

    expect($response->json('props.title'))->toBe(__('tbtop-cms::templates.edit_title'));
});

it('renders the standard EditFooter with cancel and save on the create page', function (): void {
    $structure = test()->get('/admin/templates/create', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $names = templateEditorFooterActionNames($structure);

    expect($names)->toBe(['cancel', 'save']);
});

it('renders the standard EditFooter with cancel and save on the edit page', function (): void {
    $template = Template::create(['name' => 'Landing', 'content' => []]);

    $structure = test()->get("/admin/templates/{$template->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $names = templateEditorFooterActionNames($structure);

    expect($names)->toBe(['cancel', 'save']);
});

it('labels the save action from translations', function (): void {
    $structure = test()->get('/admin/templates/create', ['X-Inertia' => 'true'])
        ->assertOk()->json('props.structure');

    $save = templateEditorFindAction($structure, 'save');

    expect($save)->not->toBeNull()
        ->and($save['options']['label'] ?? null)->toBe(__('tbtop-cms::templates.actions.save'));
});

/**
 * Action names inside the editor's footer flex node (cancel/save) — the only
 * flex node in the templateEditor form tree.
 *
 * @param  array<string, mixed>  $node
 * @return list<string>
 */
function templateEditorFooterActionNames(array $node): array
{
    $flexes = [];
    templateEditorCollectByKind($node, 'flex', $flexes);
    expect($flexes)->not->toBeEmpty();

    $footer = $flexes[array_key_last($flexes)];
    $names = [];
    foreach ((array) ($footer['options']['children'] ?? []) as $child) {
        if (is_array($child) && ($child['kind'] ?? null) === 'action') {
            $names[] = $child['name'];
        }
    }

    return $names;
}

/** @param  array<string, mixed>  $node @return array<string, mixed>|null */
function templateEditorFindAction(array $node, string $name): ?array
{
    $actions = [];
    templateEditorCollectByKind($node, 'action', $actions);

    foreach ($actions as $action) {
        if (($action['name'] ?? null) === $name) {
            return $action;
        }
    }

    return null;
}

/**
 * @param  array<string, mixed>  $node
 * @param  list<array<string, mixed>>  $out
 */
function templateEditorCollectByKind(array $node, string $kind, array &$out): void
{
    if (($node['kind'] ?? null) === $kind) {
        $out[] = $node;
    }
    foreach ((array) ($node['options']['children'] ?? []) as $child) {
        if (is_array($child)) {
            templateEditorCollectByKind($child, $kind, $out);
        }
    }
}
