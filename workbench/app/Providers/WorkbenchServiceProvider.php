<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Tbtop\Cms\Block\Blocks\HeroBlock;
use Workbench\App\Http\Middleware\AutoLoginAdmin;
use Workbench\App\Models\User;

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

        if ($this->app->runningUnitTests()) {
            return;
        }

        config([
            'auth.providers.users.model' => User::class,
            'tbtop-cms.admin.model' => User::class,
        ]);

        $this->app->make(Kernel::class)->prependMiddleware(AutoLoginAdmin::class);
    }
}
