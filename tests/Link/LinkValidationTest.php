<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\Fixtures\LinkBlock;
use Tbtop\Cms\Tests\Fixtures\RequiredLinkBlock;

uses(RefreshDatabase::class);

/**
 * The `link` field's shape rule, driven through the page editor's real save —
 * the same prefixed-field path PageEditBlockRequiredTest exercises.
 */
beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['tbtop-cms.blocks.types' => [LinkBlock::class, RequiredLinkBlock::class]]);
    Language::factory()->default()->create(['locale' => 'en']);
    Language::factory()->create(['locale' => 'uk']);
});

function linkAdmin(): AdminUser
{
    $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

function pageWithLinkBlock(string $kind = 'LinkBlock'): Page
{
    return Page::create([
        'type' => 'page',
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'content' => [['id' => 'l1', 'kind' => $kind, 'active' => true, 'sort' => 0, 'data' => []]],
    ]);
}

/** @return array<string, mixed> */
function linkPayload(mixed $link): array
{
    return [
        'name' => ['en' => 'About'],
        'title' => ['en' => ''],
        'slug' => ['en' => 'about'],
        'heading' => ['en' => ''],
        'summary' => ['en' => ''],
        'type' => 'page',
        'parent_id' => 'none',
        'status' => PageStatus::Published->value,
        'sort_order' => 0,
        'seo_title' => ['en' => ''],
        'seo_description' => ['en' => ''],
        'seo_focus_keyword' => ['en' => ''],
        'og_title' => ['en' => ''],
        'og_description' => ['en' => ''],
        'og_image' => '',
        'is_indexable' => true,
        'block_l1_active' => true,
        'block_l1_cta' => $link,
    ];
}

function saveLink(Page $page, mixed $link): TestResponse
{
    return test()->actingAs(linkAdmin(), 'web')->postJson(
        "/admin/pages/{$page->id}/edit/forms/pageEditor",
        linkPayload($link),
    );
}

// --- happy path, one per type ---

it('accepts a page link', function (): void {
    $page = pageWithLinkBlock();
    $target = Page::factory()->create(['name' => ['en' => 'Target']]);

    saveLink($page, ['type' => 'page', 'page' => (string) $target->id, 'value' => null, 'anchor' => null])
        ->assertRedirect();

    expect($page->refresh()->content[0]['data']['cta']['page'])->toBe((string) $target->id);
});

it('accepts a page link with an anchor', function (): void {
    $page = pageWithLinkBlock();
    $target = Page::factory()->create(['name' => ['en' => 'Target']]);

    saveLink($page, ['type' => 'page', 'page' => (string) $target->id, 'value' => null, 'anchor' => 'how'])
        ->assertRedirect();

    expect($page->refresh()->content[0]['data']['cta']['anchor'])->toBe('how');
});

it('accepts an external https url', function (): void {
    saveLink(pageWithLinkBlock(), ['type' => 'external', 'page' => null, 'value' => 'https://example.com/x', 'anchor' => null])
        ->assertRedirect();
});

it('accepts an email', function (): void {
    saveLink(pageWithLinkBlock(), ['type' => 'email', 'page' => null, 'value' => 'hi@example.com', 'anchor' => null])
        ->assertRedirect();
});

it('accepts a formatted phone number', function (): void {
    saveLink(pageWithLinkBlock(), ['type' => 'phone', 'page' => null, 'value' => '+380 (44) 123-45-67', 'anchor' => null])
        ->assertRedirect();
});

it('accepts a null link on an optional field', function (): void {
    saveLink(pageWithLinkBlock(), null)->assertRedirect();
});

// --- messenger deep links (allowlisted schemes) ---

it('accepts every allowlisted messenger scheme', function (string $url): void {
    saveLink(pageWithLinkBlock(), ['type' => 'external', 'page' => null, 'value' => $url, 'anchor' => null])
        ->assertRedirect();
})->with([
    'viber://chat?number=%2B380441234567',
    'tg://resolve?domain=someone',
    'whatsapp://send?phone=380441234567',
    'skype:someone?chat',
    'sms:+380441234567',
]);

it('rejects a dangerous scheme', function (string $url): void {
    saveLink(pageWithLinkBlock(), ['type' => 'external', 'page' => null, 'value' => $url, 'anchor' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['block_l1_cta']);
})->with([
    'javascript:alert(1)',
    'JAVASCRIPT:alert(1)',
    'data:text/html,x',
    'file:///etc/passwd',
    'vbscript:msgbox(1)',
]);

// --- one invalid per type ---

it('rejects a malformed external url', function (): void {
    saveLink(pageWithLinkBlock(), ['type' => 'external', 'page' => null, 'value' => 'not a url', 'anchor' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['block_l1_cta']);
});

it('rejects a malformed email', function (): void {
    saveLink(pageWithLinkBlock(), ['type' => 'email', 'page' => null, 'value' => 'not-an-email', 'anchor' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['block_l1_cta']);
});

it('rejects a phone number with too few digits', function (): void {
    saveLink(pageWithLinkBlock(), ['type' => 'phone', 'page' => null, 'value' => '+38 04', 'anchor' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['block_l1_cta']);
});

it('rejects an unknown page id', function (): void {
    saveLink(pageWithLinkBlock(), ['type' => 'page', 'page' => '999999', 'value' => null, 'anchor' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['block_l1_cta']);
});

it('rejects an anchor carrying a hash', function (): void {
    $page = pageWithLinkBlock();
    $target = Page::factory()->create(['name' => ['en' => 'Target']]);

    saveLink($page, ['type' => 'page', 'page' => (string) $target->id, 'value' => null, 'anchor' => '#how'])
        ->assertUnprocessable()->assertJsonValidationErrors(['block_l1_cta']);
});

it('rejects an unknown link type', function (): void {
    saveLink(pageWithLinkBlock(), ['type' => 'ftp', 'page' => null, 'value' => 'ftp://x', 'anchor' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['block_l1_cta']);
});

// --- required ---

it('rejects a null link on a required field', function (): void {
    saveLink(pageWithLinkBlock('RequiredLinkBlock'), null)
        ->assertUnprocessable()->assertJsonValidationErrors(['block_l1_cta']);
});

it('accepts a filled link on a required field', function (): void {
    saveLink(pageWithLinkBlock('RequiredLinkBlock'), ['type' => 'email', 'page' => null, 'value' => 'hi@example.com', 'anchor' => null])
        ->assertRedirect();
});

// --- label ---

it('accepts a label map on the declared content locales', function (): void {
    $page = pageWithLinkBlock();
    $link = ['type' => 'email', 'value' => 'hi@example.com', 'label' => ['en' => 'Mail us', 'uk' => 'Напишіть']];

    saveLink($page, $link)->assertSessionHasNoErrors();

    expect($page->fresh()->content[0]['data']['cta']['label'])->toBe(['en' => 'Mail us', 'uk' => 'Напишіть']);
});

it('accepts a label left null', function (): void {
    $page = pageWithLinkBlock();

    saveLink($page, ['type' => 'email', 'value' => 'hi@example.com', 'label' => null])
        ->assertSessionHasNoErrors();
});

it('accepts a label entry left empty for one locale', function (): void {
    $page = pageWithLinkBlock();
    $link = ['type' => 'email', 'value' => 'hi@example.com', 'label' => ['en' => 'Mail us', 'uk' => null]];

    saveLink($page, $link)->assertSessionHasNoErrors();
});

it('rejects a label keyed by an unknown locale', function (): void {
    $page = pageWithLinkBlock();
    $link = ['type' => 'email', 'value' => 'hi@example.com', 'label' => ['de' => 'Schreiben']];

    saveLink($page, $link)->assertJsonValidationErrors('block_l1_cta');
});

it('rejects a label longer than 255 characters', function (): void {
    $page = pageWithLinkBlock();
    $link = ['type' => 'email', 'value' => 'hi@example.com', 'label' => ['en' => str_repeat('a', 256)]];

    saveLink($page, $link)->assertJsonValidationErrors('block_l1_cta');
});

it('accepts a label of exactly 255 characters', function (): void {
    $page = pageWithLinkBlock();
    $link = ['type' => 'email', 'value' => 'hi@example.com', 'label' => ['en' => str_repeat('a', 255)]];

    saveLink($page, $link)->assertSessionHasNoErrors();
});

it('rejects a non-string label entry', function (): void {
    $page = pageWithLinkBlock();

    saveLink($page, ['type' => 'email', 'value' => 'hi@example.com', 'label' => ['en' => ['nested']]])
        ->assertJsonValidationErrors('block_l1_cta');
});

it('rejects a label that is a list rather than a locale map', function (): void {
    $page = pageWithLinkBlock();

    saveLink($page, ['type' => 'email', 'value' => 'hi@example.com', 'label' => ['Mail us']])
        ->assertJsonValidationErrors('block_l1_cta');
});

it('carries the content locales and the fallback for the label row', function (): void {
    $options = (new S)->link('cta')->toNode()->options;

    expect($options['locales'])->toBe(['en', 'uk'])
        ->and($options['defaultLocale'])->toBe('en');
});

it('carries the translated editor strings rather than client-side defaults', function (): void {
    app()->setLocale('uk');

    $options = (new S)->link('cta')->toNode()->options;

    expect($options['labels']['page'])->toBe('Сторінка')
        ->and($options['textLabel'])->toBe('Текст');
});
