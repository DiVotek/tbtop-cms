<?php

declare(strict_types=1);

namespace Tbtop\Cms\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tbtop\Cms\Language\Models\Language;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    /** @var class-string<Language> */
    protected $model = Language::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'locale' => fake()->unique()->languageCode(),
            'is_default' => false,
            'is_admin_active' => true,
            'is_frontend_active' => true,
            'sort_order' => 0,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => [
            'is_default' => true,
            'is_admin_active' => true,
            'is_frontend_active' => true,
        ]);
    }
}
