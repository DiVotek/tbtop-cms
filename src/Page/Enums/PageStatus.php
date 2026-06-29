<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Enums;

enum PageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
