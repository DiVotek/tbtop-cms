<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\HandlesFormSubmission;

/**
 * Opt-in for a `HandlesFormSubmission` block: its form becomes reachable by
 * name from any live page, not only where an editor placed the block. A page
 * type template (a service order form, say) renders the form from
 * `Cms::forms()->props($name, $page)` and posts to that action; no hidden
 * block in a layout slot is needed. A name-addressed submit validates and
 * handles with empty `$blockData`, since no placed row carries editor copy.
 *
 * @phpstan-require-extends Block
 *
 * @phpstan-require-implements HandlesFormSubmission
 */
trait HasForm
{
    /** The form's name in {@see Forms}; the block kind unless overridden. */
    public function formName(): string
    {
        return $this->kind();
    }
}
