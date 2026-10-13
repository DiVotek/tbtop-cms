<?php

declare(strict_types=1);

// Pins the registry to the package's own blocks: the workbench provider would add its demo FaqBlock.
// A second pass emits the form-driven test fixture blocks' schema for the resolve() ↔ zod parity test,
// and the fixture page types' schema (pages.generated.ts beside it) for the page props parity test.

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Block\Blocks\HeroBlock;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Tests\Fixtures\ConcertPageType;
use Tbtop\Cms\Tests\Fixtures\EventPageType;
use Tbtop\Cms\Tests\Fixtures\FestivalPageType;
use Tbtop\Cms\Tests\Fixtures\FormResolver\ArrayShapesBlock;
use Tbtop\Cms\Tests\Fixtures\FormResolver\EveryKindBlock;
use Tbtop\Cms\Tests\Fixtures\GigPageType;
use Tbtop\Cms\Tests\Fixtures\LegalServicePageType;
use Tbtop\Cms\Tests\Fixtures\ShadowingSubtypePageType;
use Tbtop\Cms\Tests\Fixtures\SpecialistPageType;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../vendor/orchestra/testbench-core/laravel/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// blocks file => [block classes, page type classes on top of the built-in ones]
$passes = [
    __DIR__ . '/../resources/js/cms/blocks.generated.ts' => [[HeroBlock::class, ContactForm::class], []],
    __DIR__ . '/../resources/js/cms/testing/formBlocks.generated.ts' => [
        [EveryKindBlock::class, ArrayShapesBlock::class],
        [EventPageType::class, ConcertPageType::class, FestivalPageType::class, GigPageType::class, ShadowingSubtypePageType::class, SpecialistPageType::class, LegalServicePageType::class],
    ],
];

$check = in_array('--check', $argv, true);
$status = 0;

foreach ($passes as $path => [$types, $pageTypes]) {
    config([
        'tbtop-cms.blocks.types' => $types,
        'tbtop-cms.pages.types' => $pageTypes,
        // The package ships a handwritten catalog.ts and registry.tsx next to its demo components.
        'tbtop-cms.blocks.registry_path' => false,
    ]);
    // The registry reads the configured types once, on first boot.
    $app->forgetInstance(BlockRegistry::class);
    $app->forgetInstance(PageTypeRegistry::class);

    $options = ['--path' => $path];

    if ($check) {
        $options['--check'] = true;
    }

    $status = max($status, Artisan::call('cms:blocks:types', $options));

    echo Artisan::output();
}

exit($status);
