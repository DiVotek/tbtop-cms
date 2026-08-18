<?php

declare(strict_types=1);

use Tbtop\Admin\Panels\PanelConfig;
use Tbtop\Admin\Panels\PanelRegistry;
use Tbtop\Cms\Admin\CmsChrome;
use Tbtop\Cms\Admin\CmsPanel;

it('auto-registers the CMS panel by default', function (): void {
    expect(config('tbtop-admin.panels'))->toContain(CmsPanel::class)
        ->and(app(PanelRegistry::class)->all())->toHaveKey('cms');
});

it('configures the CMS chrome for the shell', function (): void {
    $config = (new CmsPanel)->configure(new PanelConfig);

    expect($config->getChrome())->toBe(CmsChrome::class);
});

it('configures a compact, full-width appearance', function (): void {
    $config = (new CmsPanel)->configure(new PanelConfig);

    expect($config->appearance())->toMatchArray([
        'density' => 'compact',
        'maxWidth' => 'full',
    ]);
});

it('renders the panel through its own root view, not the frontend one', function (): void {
    $config = (new CmsPanel)->configure(new PanelConfig);

    // PanelConfig defaults to 'app' — the host's public root view. That view
    // loads the frontend Vite entry, which has no admin/page component, so the
    // panel would 500 on every request.
    expect($config->getRootView())->toBe('admin');
});
