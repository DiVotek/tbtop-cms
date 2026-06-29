<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend;

use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;

class PageResolver
{
    /** @var array<string, Page|null> */
    private array $memo = [];

    public function resolve(string $path, string $locale): ?Page
    {
        $path = trim($path, '/');
        $key = $locale . "\0" . $path;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->lookup($path, $locale);
    }

    private function lookup(string $path, string $locale): ?Page
    {
        $pageId = PageSlug::query()
            ->where('locale', $locale)
            ->where('path', $path)
            ->value('page_id');

        if ($pageId === null) {
            return null;
        }

        return Page::query()
            ->whereKey($pageId)
            ->where('status', PageStatus::Published->value)
            ->first();
    }
}
