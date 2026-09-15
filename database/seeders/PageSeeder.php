<?php

declare(strict_types=1);

namespace Tbtop\Cms\Database\Seeders;

use Illuminate\Database\Seeder;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\PageSlugWriter;

class PageSeeder extends Seeder
{
    public function run(): void
    {
        $locale = app(Languages::class)->defaultLocale() ?: (string) (config('app.fallback_locale') ?: 'en');
        $name = (string) config('tbtop-cms.pages.home.name', 'Home');
        $title = (string) config('tbtop-cms.pages.home.title', $name);

        $home = Page::firstOrCreate(
            ['is_system' => true, 'type' => 'page'],
            [
                'status' => PageStatus::Published,
                'name' => [$locale => $name],
                'title' => [$locale => $title],
                'is_indexable' => true,
                'sort_order' => 0,
            ],
        );

        // A host's DatabaseSeeder runs with WithoutModelEvents, so PageObserver
        // never fires; the writer is idempotent, so calling it here is safe too.
        app(PageSlugWriter::class)->sync($home);
    }
}
