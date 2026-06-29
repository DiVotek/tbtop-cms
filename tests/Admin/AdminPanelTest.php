<?php

declare(strict_types=1);

use Tbtop\Admin\Panels\PanelRegistry;
use Tbtop\Cms\Admin\CmsPanel;

it('auto-registers the CMS panel by default', function (): void {
    expect(config('tbtop-admin.panels'))->toContain(CmsPanel::class)
        ->and(app(PanelRegistry::class)->all())->toHaveKey('cms');
});
