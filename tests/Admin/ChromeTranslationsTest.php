<?php

declare(strict_types=1);

function flattenChromeKeys(array $array, string $prefix = ''): array
{
    $keys = [];
    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        if (is_array($value)) {
            $keys = [...$keys, ...flattenChromeKeys($value, $path)];
        } else {
            $keys[] = $path;
        }
    }

    return $keys;
}

it('keeps chrome translation keys identical between en and uk', function (): void {
    $enKeys = flattenChromeKeys(require __DIR__ . '/../../resources/lang/en/chrome.php');
    $ukKeys = flattenChromeKeys(require __DIR__ . '/../../resources/lang/uk/chrome.php');

    $missingInUk = array_values(array_diff($enKeys, $ukKeys));
    $missingInEn = array_values(array_diff($ukKeys, $enKeys));

    expect($missingInUk)->toBe([], 'keys present in en/chrome.php but missing in uk/chrome.php: ' . implode(', ', $missingInUk));
    expect($missingInEn)->toBe([], 'keys present in uk/chrome.php but missing in en/chrome.php: ' . implode(', ', $missingInEn));
});

it('loads the tbtop-cms chrome translations for en and uk', function (): void {
    app()->setLocale('en');
    expect(__('tbtop-cms::chrome.view_site.label'))->toBe('View site')
        ->and(__('tbtop-cms::chrome.inbox.label'))->toBe('Inbox');

    app()->setLocale('uk');
    expect(__('tbtop-cms::chrome.view_site.label'))->toBe('Переглянути сайт')
        ->and(__('tbtop-cms::chrome.inbox.label'))->toBe('Вхідні');
});
