<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Tbtop\Cms\Block\Blocks\HeroBlock;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        view()->addLocation(__DIR__ . '/../../resources/views');
        config(['tbtop-cms.blocks.types' => [HeroBlock::class]]);
    }
}
