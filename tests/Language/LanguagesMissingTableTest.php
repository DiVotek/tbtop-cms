<?php

declare(strict_types=1);

use Tbtop\Cms\Language\Languages;

it('returns an empty set when the languages table is missing', function (): void {
    $languages = app(Languages::class);

    expect($languages->all())->toBe([])
        ->and($languages->default())->toBeNull()
        ->and($languages->defaultLocale())->toBe('');
});
