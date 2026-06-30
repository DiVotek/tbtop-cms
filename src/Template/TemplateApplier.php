<?php

declare(strict_types=1);

namespace Tbtop\Cms\Template;

use Illuminate\Support\Str;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Template\Models\Template;

class TemplateApplier
{
    public function applyTo(Template $template, Page $page): Page
    {
        $page->content = [...$this->blocks($page), ...$this->copyBlocks($template)];
        $page->save();

        return $page;
    }

    /** @return list<array<string, mixed>> */
    private function copyBlocks(Template $template): array
    {
        $copied = [];

        foreach ($this->blocks($template) as $block) {
            if (is_array($block)) {
                $copied[] = [...$block, 'id' => (string) Str::ulid()];
            }
        }

        return $copied;
    }

    /** @return list<mixed> */
    private function blocks(Page | Template $model): array
    {
        return is_array($model->content) ? array_values($model->content) : [];
    }
}
