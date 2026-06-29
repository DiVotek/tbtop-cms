<?php

declare(strict_types=1);

namespace Tbtop\Cms\Database\Seeders;

use Illuminate\Database\Seeder;
use Tbtop\Cms\Language\Models\Language;

class LanguageSeeder extends Seeder
{
    /** @var list<string> */
    private const array COLUMNS = [
        'name', 'locale', 'is_default', 'is_admin_active', 'is_frontend_active', 'sort_order',
    ];

    public function run(): void
    {
        /** @var array<int, array<string, mixed>> $seed */
        $seed = (array) config('tbtop-cms.languages.seed', []);

        foreach ($seed as $row) {
            if (! isset($row['locale'])) {
                continue;
            }

            Language::firstOrCreate(['locale' => $row['locale']], $this->values($row));
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function values(array $row): array
    {
        $values = [];

        foreach (self::COLUMNS as $column) {
            if (array_key_exists($column, $row)) {
                $values[$column] = $row[$column];
            }
        }

        return $values;
    }
}
