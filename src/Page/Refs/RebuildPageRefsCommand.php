<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

use Illuminate\Console\Command;

class RebuildPageRefsCommand extends Command
{
    protected $signature = 'cms:page-refs:rebuild';

    protected $description = 'Recompute the page references index (tbtop_page_refs) from every page\'s type fields.';

    public function handle(PageRefIndex $index): int
    {
        $rows = $index->rebuild();

        $this->info("Rebuilt the page references index: {$rows} reference(s).");

        return self::SUCCESS;
    }
}
