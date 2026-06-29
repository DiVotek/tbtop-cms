<?php

declare(strict_types=1);

namespace Tbtop\Cms\Database\Seeders;

use Illuminate\Database\Seeder;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

class PageSeeder extends Seeder
{
    public function run(): void
    {
        $locale = app(Languages::class)->defaultLocale() ?: (string) (config('app.fallback_locale') ?: 'en');
        $name = (string) config('tbtop-cms.pages.home.name', 'Home');

        Page::firstOrCreate(
            ['is_system' => true, 'type' => 'page'],
            [
                'status' => PageStatus::Published,
                'name' => [$locale => $name],
                'title' => [$locale => $name],
                'is_indexable' => true,
                'sort_order' => 0,
            ],
        );
    }
}
