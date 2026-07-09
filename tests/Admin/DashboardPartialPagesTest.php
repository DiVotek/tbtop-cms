<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\TestCase;

/** Hosts publish the pages config; stale copies may omit newer pages. */
class DashboardPartialPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var array{admin: array{pages: array<string, class-string|null>}} $config */
        $config = require dirname(__DIR__, 2) . '/config/tbtop-cms.php';
        $admin = $config['admin'];
        unset($admin['pages']['layout'], $admin['pages']['layout_edit']);

        $app['config']->set('tbtop-cms.admin', $admin);
    }

    public function test_dashboard_renders_when_layout_pages_are_not_registered(): void
    {
        config(['tbtop-cms.admin.model' => AdminUser::class]);
        config(['auth.providers.users.model' => AdminUser::class]);
        Language::factory()->default()->create(['locale' => 'en']);

        $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
        CmsUserProfile::create(['user_id' => $user->id]);
        $this->actingAs($user, 'web');

        $this->assertFalse(Route::has('tbtop.cms.layout-page'));

        $response = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])->assertOk();

        // The pages route is still registered in this fixture — the "New page"
        // header action and the recent-pages section action stay present.
        $names = array_column($response->json('props.headerActions'), 'name');
        $this->assertContains('dashboardNewPage', $names);
    }
}
