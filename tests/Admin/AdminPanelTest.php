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
