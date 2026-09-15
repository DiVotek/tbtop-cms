<?php

declare(strict_types=1);

namespace Tbtop\Cms\Database\Seeders;

use Illuminate\Database\Seeder;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\ErrorClass;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\PageSlugWriter;

class PageSeeder extends Seeder
{
    public function run(): void
    {
        $locale = app(Languages::class)->defaultLocale() ?: (string) (config('app.fallback_locale') ?: 'en');

        $this->seedHome($locale);
        $this->seedErrorPage(ErrorClass::ClientError, $locale);
        $this->seedErrorPage(ErrorClass::ServerError, $locale);
    }

    private function seedHome(string $locale): void
    {
        $name = (string) config('tbtop-cms.pages.home.name', 'Home');
        $title = (string) config('tbtop-cms.pages.home.title', $name);

        // error_class is part of the lookup, not just the payload: the error
        // pages below share is_system=true and type='page', so without it
        // this would match whichever of the three rows came first.
        $home = Page::firstOrCreate(
            ['is_system' => true, 'type' => 'page', 'error_class' => null],
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

    private function seedErrorPage(ErrorClass $errorClass, string $locale): void
    {
        $name = (string) config("tbtop-cms.pages.errors.{$errorClass->value}.name", $errorClass->value);
        $title = (string) config("tbtop-cms.pages.errors.{$errorClass->value}.title", $name);
        $heading = (string) __("tbtop-cms::errors.{$errorClass->value}.heading");

        Page::firstOrCreate(
            ['error_class' => $errorClass],
            [
                'is_system' => true,
                'type' => 'page',
                'status' => PageStatus::Published,
                'name' => [$locale => $name],
                'title' => [$locale => $title],
                'heading' => [$locale => $heading],
                'is_indexable' => false,
                'sort_order' => 0,
            ],
        );
    }
}
