<?php

declare(strict_types=1);

use Tbtop\Cms\Settings\Support\SettingCodec;

it('round-trips a non-secret array value', function (): void {
    $encoded = SettingCodec::encode(['a' => 1, 'b' => [2, 3]], false);

    expect(SettingCodec::decode($encoded, false))->toBe(['a' => 1, 'b' => [2, 3]]);
});

it('round-trips a non-secret scalar value', function (): void {
    expect(SettingCodec::decode(SettingCodec::encode('plain', false), false))->toBe('plain');
});

it('encrypts a secret value at rest and decrypts it back', function (): void {
    $encoded = SettingCodec::encode('hunter2', true);

    expect($encoded)->not->toContain('hunter2')
        ->and(SettingCodec::decode($encoded, true))->toBe('hunter2');
});

it('decodes a null value as null', function (): void {
    expect(SettingCodec::decode(null, false))->toBeNull();
});
