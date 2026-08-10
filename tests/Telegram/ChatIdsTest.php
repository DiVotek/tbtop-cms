<?php

declare(strict_types=1);

use Tbtop\Cms\Telegram\ChatIds;

it('parses ids separated by commas, semicolons, and newlines, trimming whitespace', function (): void {
    expect(ChatIds::parse("111, 222;\n 333"))->toBe(['111', '222', '333']);
});

it('keeps negative ids — Telegram groups use negative chat ids', function (): void {
    expect(ChatIds::parse('-1001234567890'))->toBe(['-1001234567890']);
});

it('drops non-numeric entries', function (): void {
    expect(ChatIds::parse('111, not-an-id, 222'))->toBe(['111', '222']);
});

it('deduplicates repeated ids', function (): void {
    expect(ChatIds::parse("111\n111\n222"))->toBe(['111', '222']);
});

it('returns an empty list for blank or non-string input', function (): void {
    expect(ChatIds::parse(null))->toBe([])
        ->and(ChatIds::parse(''))->toBe([])
        ->and(ChatIds::parse('   '))->toBe([]);
});

it('appends a new id to the existing list', function (): void {
    expect(ChatIds::append("111\n222", '333'))->toBe("111\n222\n333");
});

it('does not duplicate an id that is already present', function (): void {
    expect(ChatIds::append('111', '111'))->toBe('111');
});

it('appends to an empty starting value', function (): void {
    expect(ChatIds::append(null, '111'))->toBe('111');
});

it('ignores labels after # when parsing', function (): void {
    expect(ChatIds::parse("111 # Dima (@dima)\n222 # Team group"))->toBe(['111', '222']);
});

it('appends an id with its label and keeps existing labeled lines verbatim', function (): void {
    expect(ChatIds::append('111 # Dima', '222', 'Olena (@olena)'))
        ->toBe("111 # Dima\n222 # Olena (@olena)");
});

it('does not duplicate an id that is already present with a label', function (): void {
    expect(ChatIds::append('111 # Dima', '111', 'Someone Else'))->toBe('111 # Dima');
});

it('strips separator characters from the label so the line stays parseable', function (): void {
    expect(ChatIds::append(null, '111', "A#B,C;D\nE"))->toBe('111 # A B C D E');
});
