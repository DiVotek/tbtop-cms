<?php

declare(strict_types=1);

function flattenNavKeys(array $array, string $prefix = ''): array
{
    $keys = [];
    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        if (is_array($value)) {
            $keys = [...$keys, ...flattenNavKeys($value, $path)];
        } else {
            $keys[] = $path;
        }
    }

    return $keys;
}

it('keeps nav translation keys identical between en and uk', function (): void {
    $enKeys = flattenNavKeys(require __DIR__ . '/../../resources/lang/en/nav.php');
    $ukKeys = flattenNavKeys(require __DIR__ . '/../../resources/lang/uk/nav.php');

    $missingInUk = array_values(array_diff($enKeys, $ukKeys));
    $missingInEn = array_values(array_diff($ukKeys, $enKeys));

    expect($missingInUk)->toBe([], 'keys present in en/nav.php but missing in uk/nav.php: ' . implode(', ', $missingInUk));
    expect($missingInEn)->toBe([], 'keys present in uk/nav.php but missing in en/nav.php: ' . implode(', ', $missingInEn));
});

it('loads the tbtop-cms nav translations for en and uk', function (): void {
    app()->setLocale('en');
    expect(__('tbtop-cms::nav.groups.content'))->toBe('Content');

    app()->setLocale('uk');
    expect(__('tbtop-cms::nav.groups.content'))->toBe('Контент');
});
