<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds source_type and source_id to tbtop_pages after migrate', function (): void {
    expect(Schema::hasColumn('tbtop_pages', 'source_type'))->toBeTrue()
        ->and(Schema::hasColumn('tbtop_pages', 'source_id'))->toBeTrue();
});
