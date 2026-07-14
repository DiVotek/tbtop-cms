<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Pages\MediaLibraryPage as AdminMediaLibraryPage;

/**
 * Surfaces the admin package's media library in the CMS sidebar.
 * The library UI, upload/import routes and models all ship with admin —
 * this subclass only fixes the nav group key and localizes the labels.
 */
class MediaLibraryPage extends AdminMediaLibraryPage
{
    /** @return array<string, mixed> */
    #[\Override]
    public static function nav(): ?array
    {
        // Lowercase 'content' matches CmsPanel's NavGroup::make('content');
        // the parent returns 'Content', which would sort into a stray group.
        return [
            'group' => 'content',
            'label' => __('tbtop-cms::nav.items.media_library'),
            'order' => 30,
        ];
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::nav.items.media_library');
    }
}
