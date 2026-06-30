<?php

declare(strict_types=1);

namespace Tbtop\Cms\Redirect\Observers;

use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Redirect\Support\RedirectWriter;

class PageSlugRedirects
{
    public function __construct(private readonly RedirectWriter $writer) {}

    public function updated(PageSlug $slug): void
    {
        if (! $slug->wasChanged('path')) {
            return;
        }

        $this->writer->recordMove($slug->locale, (string) $slug->getOriginal('path'), $slug->path);
    }

    public function saved(PageSlug $slug): void
    {
        $this->writer->clearAt($slug->locale, $slug->path);
    }
}
