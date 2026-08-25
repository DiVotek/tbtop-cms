<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Admin\Pages\Support\PageSortKey;

it('casts page ids to text before padding them on PostgreSQL', function (): void {
    $database = DB::getFacadeRoot();

    try {
        DB::shouldReceive('connection->getDriverName')->andReturn('pgsql');

        $padId = new ReflectionMethod(PageSortKey::class, 'padId');

        expect($padId->invoke(null, 'tbtop_pages.id'))
            ->toBe("LPAD(CAST(tbtop_pages.id AS TEXT), 10, '0')");
    } finally {
        DB::swap($database);
    }
});
