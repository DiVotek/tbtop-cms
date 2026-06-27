<?php

declare(strict_types=1);

arch('source uses strict types')
    ->expect('Tbtop\Cms')
    ->toUseStrictTypes();

arch('source ships no debug helpers')
    ->expect('Tbtop\Cms')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray', 'print_r']);

arch()->preset()->security();
