<?php

declare(strict_types=1);

/**
 * Regenerates resources/js/cms/blocks.generated.ts for exactly
 * Hero/ContactForm — overrides tbtop-cms.blocks.types after boot to exclude
 * the workbench's own demo-only FaqBlock registration.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Block\Blocks\HeroBlock;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['tbtop-cms.blocks.types' => [HeroBlock::class, ContactForm::class]]);

$options = ['--path' => __DIR__ . '/../resources/js/cms/blocks.generated.ts'];

if (in_array('--check', $argv, true)) {
    $options['--check'] = true;
}

$status = Artisan::call('cms:blocks:types', $options);

echo Artisan::output();

exit($status);
