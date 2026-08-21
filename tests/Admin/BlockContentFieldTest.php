<?php

declare(strict_types=1);

use Tbtop\Cms\Admin\Pages\Support\BlockContentField;

it('preserves numeric sort values through the editable row round trip', function (): void {
    $content = [
        ['id' => 'hero', 'kind' => 'Hero', 'active' => true, 'sort' => 20, 'data' => ['title' => 'Hello']],
        ['id' => 'cta', 'kind' => 'Cta', 'active' => false, 'sort' => '5', 'data' => ['label' => 'Join']],
    ];

    $roundTripped = BlockContentField::toEnvelope(BlockContentField::toRows($content));

    expect($roundTripped)->toBe([
        ['id' => 'hero', 'kind' => 'Hero', 'active' => true, 'sort' => 20, 'data' => ['title' => 'Hello']],
        ['id' => 'cta', 'kind' => 'Cta', 'active' => false, 'sort' => 5, 'data' => ['label' => 'Join']],
    ]);
});
