<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Admin\Pages\Support\LanguageForm;
use Tbtop\Cms\Admin\Pages\Support\LayoutForm;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Admin\Pages\Support\SettingsForm;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Form\Events\SubmissionReceived;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Language\Events\LanguagesChanged;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Events\LayoutSaved;
use Tbtop\Cms\Layout\Slots;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Events\PageDeleted;
use Tbtop\Cms\Page\Events\PagePublished;
use Tbtop\Cms\Page\Events\PageRestored;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Events\PageUnpublished;
use Tbtop\Cms\Page\Exceptions\PageConflictException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageRevision;
use Tbtop\Cms\Page\Support\PageRevisionWriter;
use Tbtop\Cms\Settings\Events\SettingsSaved;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\AdminUser;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['tbtop-cms.admin.model' => AdminUser::class]);
    config(['auth.providers.users.model' => AdminUser::class]);
    config(['cache.default' => 'array']);
    Language::factory()->default()->create(['locale' => 'en']);
});

function makeEventsAdmin(): AdminUser
{
    static $n = 0;
    $n++;
    $user = AdminUser::create(['name' => 'Admin ' . $n, 'email' => "events-admin{$n}@example.com", 'password' => 'secret']);
    CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

    return $user;
}

// --- Page: PageForm::save() ---

it('raises PageSaved with wasCreated=true when a page is created', function (): void {
    Event::fake([PageSaved::class]);

    $page = PageForm::save(['name' => ['en' => 'About'], 'title' => ['en' => ''], 'slug' => ['en' => ''], 'type' => 'page', 'parent_id' => 'none']);

    Event::assertDispatched(PageSaved::class, fn (PageSaved $e): bool => $e->page->is($page) && $e->wasCreated === true);
});

it('raises PageSaved with wasCreated=false when an existing page is updated', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'About']]);
    Event::fake([PageSaved::class]);

    PageForm::save(['name' => ['en' => 'About Updated'], 'title' => ['en' => ''], 'slug' => ['en' => 'about'], 'type' => 'page', 'parent_id' => 'none', 'status' => PageStatus::Draft->value], $page->id);

    Event::assertDispatched(PageSaved::class, fn (PageSaved $e): bool => $e->wasCreated === false);
});

it('raises PagePublished when a page is created already published', function (): void {
    Event::fake([PagePublished::class, PageUnpublished::class]);

    PageForm::save(['name' => ['en' => 'About'], 'title' => ['en' => ''], 'slug' => ['en' => ''], 'type' => 'page', 'parent_id' => 'none', 'status' => PageStatus::Published->value]);

    Event::assertDispatched(PagePublished::class);
    Event::assertNotDispatched(PageUnpublished::class);
});

it('raises PagePublished on a draft->published transition, not on an unrelated save', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'About']]);
    Event::fake([PagePublished::class, PageUnpublished::class]);

    PageForm::save(['name' => ['en' => 'About'], 'title' => ['en' => ''], 'slug' => ['en' => 'about'], 'type' => 'page', 'parent_id' => 'none', 'status' => PageStatus::Published->value], $page->id);

    Event::assertDispatched(PagePublished::class, fn (PagePublished $e): bool => $e->page->is($page));
    Event::assertNotDispatched(PageUnpublished::class);
});

it('raises PageUnpublished on a published->draft transition', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    Event::fake([PagePublished::class, PageUnpublished::class]);

    PageForm::save(['name' => ['en' => 'About'], 'title' => ['en' => ''], 'slug' => ['en' => 'about'], 'type' => 'page', 'parent_id' => 'none', 'status' => PageStatus::Draft->value], $page->id);

    Event::assertDispatched(PageUnpublished::class, fn (PageUnpublished $e): bool => $e->page->is($page));
    Event::assertNotDispatched(PagePublished::class);
});

it('raises neither PagePublished nor PageUnpublished when a save does not change status', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    Event::fake([PagePublished::class, PageUnpublished::class]);

    PageForm::save(['name' => ['en' => 'About renamed'], 'title' => ['en' => ''], 'slug' => ['en' => 'about'], 'type' => 'page', 'parent_id' => 'none', 'status' => PageStatus::Published->value], $page->id);

    Event::assertNotDispatched(PagePublished::class);
    Event::assertNotDispatched(PageUnpublished::class);
});

it('does not raise PageSaved when the save fails on a lock_version conflict', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $page->update(['name' => ['en' => 'Bumped']]);
    Event::fake([PageSaved::class]);

    expect(fn () => PageForm::save([
        'name' => ['en' => 'Stale'], 'title' => ['en' => ''], 'slug' => ['en' => 'about'],
        'type' => 'page', 'parent_id' => 'none', 'status' => PageStatus::Published->value,
        'lock_version' => 0,
    ], $page->id))->toThrow(PageConflictException::class);

    Event::assertNotDispatched(PageSaved::class);
});

// --- Page: other save-adjacent write points ---

it('raises PageSaved(wasCreated: false) when a page revision is restored', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Draft, 'name' => ['en' => 'Original']]);
    $revision = PageRevision::query()->where('page_id', $page->id)->where('kind', 'create')->firstOrFail();
    $page->update(['name' => ['en' => 'Changed']]);
    Event::fake([PageSaved::class]);

    (new PageRevisionWriter)->restore($page, $revision);

    Event::assertDispatched(PageSaved::class, fn (PageSaved $e): bool => $e->page->is($page) && $e->wasCreated === false);
});

// --- Page: delete/restore via admin HTTP actions ---

it('raises PageDeleted(forced: false) when a page is deleted from the edit page', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    Event::fake([PageDeleted::class]);

    test()->actingAs(makeEventsAdmin(), 'web')
        ->postJson("/admin/pages/{$page->id}/edit/actions/pageEditDelete", ['payload' => []])
        ->assertOk();

    Event::assertDispatched(PageDeleted::class, fn (PageDeleted $e): bool => $e->page->getKey() === $page->getKey() && $e->forced === false);
});

it('raises PageRestored when a trashed page is restored from the pages list', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $page->delete();
    Event::fake([PageRestored::class]);

    test()->actingAs(makeEventsAdmin(), 'web')
        ->postJson('/admin/pages/actions/pageRestore', ['payload' => ['row' => ['id' => $page->id]]])
        ->assertOk();

    Event::assertDispatched(PageRestored::class, fn (PageRestored $e): bool => $e->page->getKey() === $page->getKey());
});

it('raises PageDeleted(forced: true) when a trashed page is force-deleted from the pages list', function (): void {
    $page = Page::create(['type' => 'page', 'status' => PageStatus::Published, 'name' => ['en' => 'About']]);
    $page->delete();
    Event::fake([PageDeleted::class]);

    test()->actingAs(makeEventsAdmin(), 'web')
        ->postJson('/admin/pages/actions/pageForceDelete', ['payload' => ['row' => ['id' => $page->id]]])
        ->assertOk();

    Event::assertDispatched(PageDeleted::class, fn (PageDeleted $e): bool => $e->page->getKey() === $page->id && $e->forced === true);
});

// --- Form: SubmissionReceived ---

function eventsContactFormPage(): Page
{
    return Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'Contact'],
        'content' => [[
            'id' => 'form-1',
            'kind' => 'ContactForm',
            'active' => true,
            'data' => ['recipient' => 'ops@example.test'],
        ]],
    ]);
}

it('raises SubmissionReceived with the stored submission on a normal submit', function (): void {
    config()->set('tbtop-cms.blocks.types', [ContactForm::class]);
    $page = eventsContactFormPage();
    app(Cms::class)->frontendRoutes();
    Event::fake([SubmissionReceived::class]);

    test()->post(route('tbtop-cms.form.submit', ['locale' => 'en', 'page' => $page->id, 'block' => 'form-1']), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hello there',
    ])->assertRedirect();

    Event::assertDispatched(SubmissionReceived::class, function (SubmissionReceived $e) use ($page): bool {
        return $e->submission instanceof FormSubmission
            && $e->page->is($page)
            && $e->blockId === 'form-1'
            && $e->locale === 'en'
            && $e->payload['name'] === 'Ada';
    });
});

it('raises SubmissionReceived with a null submission when forms.store is disabled', function (): void {
    config()->set('tbtop-cms.blocks.types', [ContactForm::class]);
    config()->set('tbtop-cms.forms.store', false);
    $page = eventsContactFormPage();
    app(Cms::class)->frontendRoutes();
    Event::fake([SubmissionReceived::class]);

    test()->post(route('tbtop-cms.form.submit', ['locale' => 'en', 'page' => $page->id, 'block' => 'form-1']), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hello there',
    ])->assertRedirect();

    Event::assertDispatched(SubmissionReceived::class, fn (SubmissionReceived $e): bool => $e->submission === null);
});

it('does not raise SubmissionReceived when the honeypot field is tripped', function (): void {
    config()->set('tbtop-cms.blocks.types', [ContactForm::class]);
    $page = eventsContactFormPage();
    app(Cms::class)->frontendRoutes();
    Event::fake([SubmissionReceived::class]);

    test()->post(route('tbtop-cms.form.submit', ['locale' => 'en', 'page' => $page->id, 'block' => 'form-1']), [
        'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hello there',
        'website' => 'https://spam.example.test',
    ])->assertRedirect();

    Event::assertNotDispatched(SubmissionReceived::class);
});

// --- Settings: SettingsSaved ---

it('raises SettingsSaved once per apply() call, carrying the store keys actually written', function (): void {
    Event::fake([SettingsSaved::class]);
    $settings = app(Settings::class);

    // seo_title is absent from the form and must not be reported as saved.
    SettingsForm::apply(
        ['site_name' => 'Acme'],
        ['site_name' => 'site.name', 'seo_title' => 'seo.title'],
        $settings,
    );

    Event::assertDispatched(SettingsSaved::class, 1);
    Event::assertDispatched(SettingsSaved::class, fn (SettingsSaved $e): bool => $e->keys === ['site.name']);
});

it('does not raise SettingsSaved when the submission writes nothing', function (): void {
    Event::fake([SettingsSaved::class]);

    SettingsForm::apply([], ['site_name' => 'site.name'], app(Settings::class));

    Event::assertNotDispatched(SettingsSaved::class);
});

// --- Layout: LayoutSaved ---

it('raises LayoutSaved when a layout slot is saved', function (): void {
    Event::fake([LayoutSaved::class]);

    $layout = LayoutForm::save(['content' => [['kind' => 'Hero', 'title' => 'Hi']]], Slots::HEADER);

    Event::assertDispatched(LayoutSaved::class, fn (LayoutSaved $e): bool => $e->layout->is($layout));
});

// --- Language: LanguagesChanged ---

it('raises LanguagesChanged when a language is saved', function (): void {
    Event::fake([LanguagesChanged::class]);

    LanguageForm::save(['locale' => 'uk', 'is_default' => false, 'is_admin_active' => true, 'is_frontend_active' => true, 'sort_order' => 1]);

    Event::assertDispatched(LanguagesChanged::class);
});

it('raises LanguagesChanged when a language is soft-deleted, restored, and force-deleted', function (): void {
    $language = Language::factory()->create(['locale' => 'de']);
    $admin = makeEventsAdmin();

    Event::fake([LanguagesChanged::class]);
    test()->actingAs($admin, 'web')
        ->postJson('/admin/languages/actions/languageDelete', ['payload' => ['row' => ['id' => $language->id]]])
        ->assertOk();
    Event::assertDispatched(LanguagesChanged::class, 1);

    Event::fake([LanguagesChanged::class]);
    test()->actingAs($admin, 'web')
        ->postJson('/admin/languages/actions/languageRestore', ['payload' => ['row' => ['id' => $language->id]]])
        ->assertOk();
    Event::assertDispatched(LanguagesChanged::class, 1);

    Event::fake([LanguagesChanged::class]);
    test()->actingAs($admin, 'web')
        ->postJson('/admin/languages/actions/languageForceDelete', ['payload' => ['row' => ['id' => $language->id]]])
        ->assertOk();
    Event::assertDispatched(LanguagesChanged::class, 1);
});
