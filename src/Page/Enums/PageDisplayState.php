<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Enums;

/**
 * The editor-facing status a page actually shows as, once scheduling is
 * folded in. PageStatus stays the editor's stored intent (draft/published);
 * this is the derived read model — see Page::displayState().
 */
enum PageDisplayState: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Expired = 'expired';
}
