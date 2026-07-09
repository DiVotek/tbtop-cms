<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Tests\Fixtures\AdminUser;
use Tbtop\Cms\Tests\TestCase;

/** Hosts that disable the Pages admin page entirely lose its dashboard links. */
class DashboardWithoutPagesRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var array{admin: array{pages: array<string, class-string|null>}} $config */
        $config = require dirname(__DIR__, 2) . '/config/tbtop-cms.php';
        $admin = $config['admin'];
        unset($admin['pages']['pages']);

        $app['config']->set('tbtop-cms.admin', $admin);
    }

    public function test_dashboard_hides_pages_links_when_the_pages_route_is_unavailable(): void
    {
        config(['tbtop-cms.admin.model' => AdminUser::class]);
        config(['auth.providers.users.model' => AdminUser::class]);
        Language::factory()->default()->create(['locale' => 'en']);

        $user = AdminUser::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
        CmsUserProfile::create(['user_id' => $user->id]);
        $this->actingAs($user, 'web');

        $this->assertFalse(Route::has('tbtop.cms.pages-page'));

        $response = $this->get('/admin/dashboard', ['X-Inertia' => 'true'])->assertOk();

        $names = array_column($response->json('props.headerActions'), 'name');
        $this->assertNotContains('dashboardNewPage', $names);

        $structure = $response->json('props.structure');
        $encoded = json_encode($structure, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(__('tbtop-cms::dashboard.recent.open_pages'), $encoded);
    }
}
