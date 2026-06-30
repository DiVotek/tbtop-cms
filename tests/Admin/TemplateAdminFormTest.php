<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

it('creates a template with blocks from the admin save path', function (): void {
    $template = TemplateForm::save([
        'name' => 'Landing',
        'content' => '[{"id":"a","kind":"Hero","data":{"title":"Hi"}}]',
    ]);

    expect($template->exists)->toBeTrue()
        ->and($template->name)->toBe('Landing')
        ->and($template->content)->toHaveCount(1)
        ->and($template->content[0]['kind'])->toBe('Hero');
});

it('rejects invalid JSON for the blocks field', function (): void {
    TemplateForm::save(['name' => 'Bad', 'content' => 'not json']);
})->throws(ValidationException::class);

it('round-trips template content to a JSON string for editing', function (): void {
    $template = Template::create([
        'name' => 'Landing',
        'content' => [['id' => 'a', 'kind' => 'Hero', 'data' => ['title' => 'Hi']]],
    ]);

    $row = TemplateForm::loadRow($template->id);

    expect($row['name'])->toBe('Landing')
        ->and($row['content'])->toBeString()
        ->and(json_decode((string) $row['content'], true))->toBe($template->content);
});

it('shows the templates admin to an admin user', function (): void {
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::create(['user_id' => $user->id]);

    $this->actingAs($user, 'web')
        ->get('/admin/templates', ['X-Inertia' => 'true'])
        ->assertOk();
});

it('redirects a guest away from the templates admin', function (): void {
    $this->get('/admin/templates')->assertRedirect(route('login'));
});
