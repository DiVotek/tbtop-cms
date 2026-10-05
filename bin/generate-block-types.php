<?php

declare(strict_types=1);

// Pins the registry to the package's own blocks: the workbench provider would add its demo FaqBlock.

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Block\Blocks\HeroBlock;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'tbtop-cms.blocks.types' => [HeroBlock::class, ContactForm::class],
    // The package ships a handwritten catalog.ts and registry.tsx next to its demo components.
    'tbtop-cms.blocks.registry_path' => false,
]);

$options = ['--path' => __DIR__ . '/../resources/js/cms/blocks.generated.ts'];

if (in_array('--check', $argv, true)) {
    $options['--check'] = true;
}

$status = Artisan::call('cms:blocks:types', $options);

echo Artisan::output();

exit($status);
