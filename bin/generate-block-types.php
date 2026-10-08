<?php

declare(strict_types=1);

// Pins the registry to the package's own blocks: the workbench provider would add its demo FaqBlock.
// A second pass emits the form-driven test fixture blocks' schema for the resolve() ↔ zod parity test.

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Block\Blocks\HeroBlock;
use Tbtop\Cms\Tests\Fixtures\FormResolver\ArrayShapesBlock;
use Tbtop\Cms\Tests\Fixtures\FormResolver\EveryKindBlock;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$passes = [
    __DIR__ . '/../resources/js/cms/blocks.generated.ts' => [HeroBlock::class, ContactForm::class],
    __DIR__ . '/../resources/js/cms/testing/formBlocks.generated.ts' => [EveryKindBlock::class, ArrayShapesBlock::class],
];

$check = in_array('--check', $argv, true);
$status = 0;

foreach ($passes as $path => $types) {
    config([
        'tbtop-cms.blocks.types' => $types,
        // The package ships a handwritten catalog.ts and registry.tsx next to its demo components.
        'tbtop-cms.blocks.registry_path' => false,
    ]);
    // The registry reads the configured types once, on first boot.
    $app->forgetInstance(BlockRegistry::class);

    $options = ['--path' => $path];

    if ($check) {
        $options['--check'] = true;
    }

    $status = max($status, Artisan::call('cms:blocks:types', $options));

    echo Artisan::output();
}

exit($status);
