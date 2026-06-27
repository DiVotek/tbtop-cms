<?php

declare(strict_types=1);

use Tbtop\Cms\CmsServiceProvider;

it('registers the package service provider', function (): void {
    expect(app()->getLoadedProviders())
        ->toHaveKey(CmsServiceProvider::class);
});

it('merges the package configuration', function (): void {
    expect(config('tbtop-cms'))->toBeArray();
});

it('boots the package inside the workbench application', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertExactJson(['package' => 'tbtop-cms', 'booted' => true]);
});
