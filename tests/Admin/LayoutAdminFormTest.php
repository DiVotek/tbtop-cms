<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\LayoutForm;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function makeLayoutAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);

    return $user;
}

it('creates a layout row via updateOrCreate on first save', function (): void {
    $layout = LayoutForm::save([
        'content' => [['kind' => 'Hero', 'title' => 'Hi']],
    ], Slots::HEADER);

    expect(Layout::query()->where('slot', Slots::HEADER)->count())->toBe(1)
        ->and($layout->content)->toHaveCount(1)
        ->and($layout->content[0]['kind'])->toBe('Hero')
        ->and($layout->content[0]['data'])->toBe(['title' => 'Hi']);
});

it('updates the existing row for a slot instead of duplicating it', function (): void {
    LayoutForm::save(['content' => [['kind' => 'Hero', 'title' => 'First']]], Slots::HEADER);
    LayoutForm::save(['content' => [['kind' => 'Hero', 'title' => 'Second']]], Slots::HEADER);

    expect(Layout::query()->where('slot', Slots::HEADER)->count())->toBe(1);

    $layout = Layout::query()->where('slot', Slots::HEADER)->firstOrFail();
    expect($layout->content[0]['data'])->toBe(['title' => 'Second']);
});

it('does not touch the footer slot when saving the header slot', function (): void {
    LayoutForm::save(['content' => [['kind' => 'Hero', 'title' => 'Footer content']]], Slots::FOOTER);
    LayoutForm::save(['content' => [['kind' => 'Hero', 'title' => 'Header content']]], Slots::HEADER);

    $footer = Layout::query()->where('slot', Slots::FOOTER)->firstOrFail();
    expect($footer->content[0]['data'])->toBe(['title' => 'Footer content']);
});

it('flattens layout content into repeater rows for editing', function (): void {
    Layout::query()->create([
        'slot' => Slots::HEADER,
        'content' => [['id' => 'a', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Hi']]],
    ]);

    $row = LayoutForm::loadRow(Slots::HEADER);

    expect($row['content'][0]['id'])->toBe('a')
        ->and($row['content'][0]['kind'])->toBe('Hero')
        ->and($row['content'][0]['title'])->toBe('Hi');
});

it('renders an empty builder for a slot with no row yet', function (): void {
    expect(LayoutForm::loadRow(Slots::HEADER))->toBe(['content' => []]);
});

it('shows the layout list page to an admin user', function (): void {
    $this->actingAs(makeLayoutAdmin(), 'web')
        ->get('/admin/layout', ['X-Inertia' => 'true'])
        ->assertOk();
});

it('shows the layout edit page with the slot label capitalized', function (): void {
    $this->actingAs(makeLayoutAdmin(), 'web')
        ->get('/admin/layout/' . Slots::HEADER . '/edit', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.title', 'Edit Header layout');
});

it('prefills the edit page with the existing slot record', function (): void {
    Layout::query()->create([
        'slot' => Slots::FOOTER,
        'content' => [['id' => 'a', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Hi']]],
    ]);

    $this->actingAs(makeLayoutAdmin(), 'web')
        ->get('/admin/layout/' . Slots::FOOTER . '/edit', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.data.layoutEditor.content.0.title', 'Hi');
});

it('redirects a guest away from the layout admin', function (): void {
    $this->get('/admin/layout')->assertRedirect(route('login'));
});
