<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

/** Plain strings, not `*` — must be safe as a select value and translation key. */
final class Placement
{
    /** Header/footer layout slots. */
    public const LAYOUT = 'layout';

    /** Wildcard: every page type, including one a host registers later. */
    public const PAGES = 'pages';
}
