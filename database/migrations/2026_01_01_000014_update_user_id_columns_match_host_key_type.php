<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Support\UserKeyColumn;

return new class extends Migration
{
    /** @var list<array{table: string, column: string, nullable: bool}> */
    private const USER_ID_COLUMNS = [
        ['table' => 'tbtop_user_profiles', 'column' => 'user_id', 'nullable' => false],
        ['table' => 'tbtop_page_revisions', 'column' => 'user_id', 'nullable' => true],
        ['table' => 'tbtop_pages', 'column' => 'created_by', 'nullable' => true],
        ['table' => 'tbtop_pages', 'column' => 'updated_by', 'nullable' => true],
        ['table' => 'tbtop_templates', 'column' => 'created_by', 'nullable' => true],
        ['table' => 'tbtop_templates', 'column' => 'updated_by', 'nullable' => true],
        ['table' => 'tbtop_redirects', 'column' => 'created_by', 'nullable' => true],
        ['table' => 'tbtop_redirects', 'column' => 'updated_by', 'nullable' => true],
    ];

    public function up(): void
    {
        foreach (self::USER_ID_COLUMNS as $spec) {
            $this->alignColumn($spec['table'], $spec['column'], $spec['nullable']);
        }
    }

    public function down(): void
    {
        //
    }

    private function alignColumn(string $table, string $column, bool $nullable): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        if (UserKeyColumn::matchesSchemaType(
            Schema::getColumnType($table, $column),
            Schema::getColumnType($table, $column, true),
        )) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column, $nullable): void {
            $definition = UserKeyColumn::define($table, $column)->change();

            if ($nullable) {
                $definition->nullable();
            }
        });
    }
};
