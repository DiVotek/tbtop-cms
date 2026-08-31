<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds source_id to tbtop_pages after migrate, with no separate source_type column', function (): void {
    expect(Schema::hasColumn('tbtop_pages', 'source_id'))->toBeTrue()
        ->and(Schema::hasColumn('tbtop_pages', 'source_type'))->toBeFalse();
});
