<?php

declare(strict_types=1);

namespace Tbtop\Cms\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    /** @var class-string<Page> */
    protected $model = Page::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'type' => 'page',
            'status' => PageStatus::Draft,
            'name' => ['en' => $title],
            'title' => ['en' => $title],
            'is_indexable' => true,
            'is_system' => false,
            'sort_order' => 0,
        ];
    }

    public function category(): static
    {
        return $this->state(fn (): array => ['type' => 'category']);
    }

    public function article(): static
    {
        return $this->state(fn (): array => ['type' => 'article']);
    }
}
