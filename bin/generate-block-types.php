<?php

declare(strict_types=1);

/**
 * Regenerates the package's own resources/js/cms/blocks.generated.ts from
 * Hero/ContactForm's docblocks (invoked by the `types:blocks`/
 * `types:blocks:check` composer scripts). A plain `php artisan` can't do
 * this alone: the package ships no default registered blocks (config
 * tbtop-cms.blocks.types is empty by design — hosts opt in), and the
 * Testbench workbench used for `composer serve`/tests also registers a
 * demo-only Workbench\App\Blocks\FaqBlock alongside Hero/ContactForm that
 * has no place in the package's own catalog. Booting via Testbench and then
 * overriding the config again, before BlockRegistry's own lazy boot() first
 * runs, keeps this generation scoped to exactly the two blocks catalog.ts
 * actually ships.
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
