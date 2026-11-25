<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\PageNameSearch;

/**
 * A plain PDO whose sqlite connection takes no user function, as on the
 * PHP 8.4 builds where `PDO::connect('sqlite:…')` returns neither a
 * Pdo\Sqlite nor a PDO with sqliteCreateFunction().
 */
final class NoUserFunctionsPdo extends PDO
{
    public function sqliteCreateFunction($function_name, $callback, $num_args = -1, $flags = 0): bool
    {
        return false;
    }
}

/** The build from the report: the method is missing, so calling it throws. */
final class MissingUserFunctionsPdo extends PDO
{
    public function sqliteCreateFunction($function_name, $callback, $num_args = -1, $flags = 0): bool
    {
        throw new Error('Call to undefined method PDO::sqliteCreateFunction()');
    }
}

it('falls back to SQLite lower() when the connection takes no user function', function (string $pdoClass): void {
    Log::spy();
    $connection = DB::connection();
    $readPdo = $connection->getReadPdo();
    $connection->setReadPdo(new $pdoClass('sqlite::memory:'));

    try {
        $query = Page::query();
        PageNameSearch::apply($query, 'незаконної мобіл', ['uk']);
        PageNameSearch::apply(Page::query(), 'мобіл', ['uk']);
        $sql = $query->toSql();
    } finally {
        $connection->setReadPdo($readPdo);
    }

    expect($sql)->toContain('lower(')->not->toContain('tbtop_lower(');
    Log::shouldHaveReceived('warning')->once();
})->with([
    'registration refused' => NoUserFunctionsPdo::class,
    'method missing' => MissingUserFunctionsPdo::class,
]);

it('lower-cases through tbtop_lower when the connection takes one', function (): void {
    $query = Page::query();
    PageNameSearch::apply($query, 'мобіл', ['uk']);

    expect($query->toSql())->toContain('tbtop_lower(');
});
