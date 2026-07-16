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

    public function resolve(string $path, string $locale, bool $withDrafts = false): ?Page
    {
        $path = trim($path, '/');
        $key = $locale . "\0" . $path . "\0" . ($withDrafts ? '1' : '0');

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $this->lookup($path, $locale, $withDrafts);
    }

    private function lookup(string $path, string $locale, bool $withDrafts): ?Page
    {
        $pageId = PageSlug::query()
            ->where('locale', $locale)
            ->where('path', $path)
            ->value('page_id');

        if ($pageId === null) {
            return null;
        }

        $query = Page::query()->whereKey($pageId);

        if (! $withDrafts) {
            $query->where('status', PageStatus::Published->value);
        }

        return $query->first();
    }
}
