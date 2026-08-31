<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Page\Enums\PageStatus;

/**
 * Fixtures for the page-edit admin screen, shared by more than one test file.
 * They live in a class rather than as plain functions in a test file because
 * paratest gives each worker only the file it runs — a sibling's functions are
 * not there. See the note in tests/Pest.php.
 */
final class PageEditHelpers
{
    public static function admin(): AdminUser
    {
        $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
        CmsUserProfile::query()->forceCreate(['user_id' => $user->id]);

        return $user;
    }

    /** @return array<string, mixed> */
    public static function structure(int $id): array
    {
        return test()->actingAs(self::admin(), 'web')
            ->get("/admin/pages/{$id}/edit", ['X-Inertia' => 'true'])
            ->assertOk()
            ->json();
    }

    /** @return list<array<string, mixed>> */
    public static function formTabs(int $pageId): array
    {
        $form = findNodeByKind(self::structure($pageId)['props']['structure'], 'form');
        $tabsNode = findNodeByKind($form, 'tabs');
        expect($tabsNode)->not->toBeNull();

        return $tabsNode['options']['tabs'];
    }

    /** @return array<string, mixed> */
    public static function baseEditPayload(): array
    {
        return [
            'name' => ['en' => 'About Us'],
            'title' => ['en' => ''],
            'slug' => ['en' => 'about-us'],
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
        ];
    }
}
