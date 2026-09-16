<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Tbtop\Cms\Admin\Models\CmsUserProfile;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Language::query()->firstOrCreate(
            ['locale' => 'en'],
            [
                'name' => 'English',
                'is_default' => true,
                'is_admin_active' => true,
                'is_frontend_active' => true,
                'sort_order' => 0,
            ],
        );

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@admin.com'],
            ['name' => 'Admin', 'password' => 'password'],
        );
        CmsUserProfile::query()->firstOrCreate(['user_id' => $admin->id]);

        if (Page::query()->where('type', 'page')->where('is_system', false)->exists()) {
            return;
        }

        $page = new Page;
        $page->type = 'page';
        $page->status = PageStatus::Published;
        $page->name = ['en' => 'Landing'];
        $page->title = ['en' => 'Landing'];
        $page->content = [
            [
                'id' => (string) Str::ulid(),
                'kind' => 'Hero',
                'active' => true,
                'data' => [
                    'title' => ['en' => 'Welcome to tbtop CMS'],
                    'subtitle' => ['en' => 'This hero block is rendered through json-render.'],
                    'image' => null,
                    'ctaLabel' => ['en' => 'View the repository'],
                    'ctaHref' => 'https://github.com/DiVotek/tbtop-cms',
                ],
            ],
            [
                'id' => (string) Str::ulid(),
                'kind' => 'faq',
                'active' => true,
                'data' => [
                    'heading' => ['en' => 'Frequently asked questions'],
                    'items' => [
                        [
                            'question' => ['en' => 'What is tbtop/cms?'],
                            'answer' => ['en' => 'A minimal CMS core for Laravel, rendered through json-render.'],
                        ],
                        [
                            'question' => ['en' => 'Does this FAQ publish structured data?'],
                            'answer' => ['en' => 'Yes — FaqBlock::toSchema() publishes a FAQPage node.'],
                        ],
                    ],
                ],
            ],
            [
                'id' => (string) Str::ulid(),
                'kind' => 'ContactForm',
                'active' => true,
                'data' => [
                    'heading' => ['en' => 'Get in touch'],
                    'recipient' => 'demo@example.test',
                ],
            ],
        ];
        $page->save();
    }
}
