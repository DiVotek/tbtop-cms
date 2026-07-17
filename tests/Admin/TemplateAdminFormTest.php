<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Admin\Enums\CmsRole;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\TemplateForm;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Template\Models\Template;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    Language::factory()->default()->create(['locale' => 'en']);
});

function makeTemplateAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id, 'role' => CmsRole::SuperAdministrator]);

    return $user;
}

it('folds repeater rows into the block envelope on save', function (): void {
    $template = TemplateForm::save([
        'name' => 'Landing',
        'content' => [
            ['kind' => 'Hero', 'title' => 'Hi'],
        ],
    ]);

    expect($template->exists)->toBeTrue()
        ->and($template->name)->toBe('Landing')
        ->and($template->content)->toHaveCount(1)
        ->and($template->content[0]['kind'])->toBe('Hero')
        ->and($template->content[0]['data'])->toBe(['title' => 'Hi'])
        ->and($template->content[0]['id'])->not->toBe('');
});

it('preserves a block kind that is not registered instead of rejecting the save', function (): void {
    // Deregistering a block type from the site config must not make records
    // that still hold one unsavable — the row round-trips verbatim.
    $template = TemplateForm::save([
        'name' => 'Landing',
        'content' => [
            ['kind' => 'NotARealBlock', 'title' => 'Hi'],
        ],
    ]);

    expect($template->content)->toHaveCount(1)
        ->and($template->content[0]['kind'])->toBe('NotARealBlock')
        ->and($template->content[0]['data'])->toBe(['title' => 'Hi']);
});

it('flattens template content into repeater rows for editing', function (): void {
    $template = Template::create([
        'name' => 'Landing',
        'content' => [['id' => 'a', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Hi']]],
    ]);

    $row = TemplateForm::loadRow($template->id);

    expect($row['name'])->toBe('Landing')
        ->and($row['content'])->toBeArray()
        ->and($row['content'][0]['id'])->toBe('a')
        ->and($row['content'][0]['kind'])->toBe('Hero')
        ->and($row['content'][0]['title'])->toBe('Hi');
});

it('shows the templates list to an admin user', function (): void {
    $this->actingAs(makeTemplateAdmin(), 'web')
        ->get('/admin/templates', ['X-Inertia' => 'true'])
        ->assertOk();
});

it('shows the template create page to an admin user', function (): void {
    $this->actingAs(makeTemplateAdmin(), 'web')
        ->get('/admin/templates/create', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'admin/page');
});

it('prefills the edit page with the template record', function (): void {
    $template = Template::create([
        'name' => 'Landing',
        'content' => [['id' => 'a', 'kind' => 'Hero', 'active' => true, 'data' => ['title' => 'Hi']]],
    ]);

    $this->actingAs(makeTemplateAdmin(), 'web')
        ->get("/admin/templates/{$template->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.data.templateEditor.name', 'Landing');
});

it('redirects a guest away from the templates admin', function (): void {
    $this->get('/admin/templates')->assertRedirect(route('login'));
});
