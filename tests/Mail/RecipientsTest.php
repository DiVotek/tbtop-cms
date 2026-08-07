<?php

declare(strict_types=1);

use Tbtop\Cms\Mail\Recipients;

it('returns an empty list for null or blank input', function (): void {
    expect(Recipients::parse(null))->toBe([])
        ->and(Recipients::parse(''))->toBe([])
        ->and(Recipients::parse('   '))->toBe([]);
});

it('splits on commas, semicolons, and newlines, trimming each piece', function (): void {
    $parsed = Recipients::parse(" ops@example.test,  sales@example.test ;support@example.test\nbilling@example.test ");

    expect($parsed)->toBe([
        'ops@example.test',
        'sales@example.test',
        'support@example.test',
        'billing@example.test',
    ]);
});

it('drops invalid email addresses while keeping valid ones', function (): void {
    $parsed = Recipients::parse("ops@example.test,not-an-email,\n,sales@example.test");

    expect($parsed)->toBe(['ops@example.test', 'sales@example.test']);
});

it('accepts non-string values by returning an empty list', function (): void {
    expect(Recipients::parse(123))->toBe([])
        ->and(Recipients::parse(['ops@example.test']))->toBe([]);
});
