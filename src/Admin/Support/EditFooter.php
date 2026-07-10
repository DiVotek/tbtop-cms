<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;

/**
 * The standard footer for every CMS edit form: [delete?] … [cancel] [save].
 * Cancel is a plain visit back to the given URL — it never touches the form,
 * so it must not trip validation. Use on all record editors for a uniform UI.
 */
final class EditFooter
{
    public static function build(S $s, string $cancelUrl, ActionBuilder $save, ?ActionBuilder $delete = null): Node
    {
        $cancel = $s->action('cancel')
            ->label(__('tbtop-cms::pages.actions.cancel'))
            ->visit($cancelUrl);

        $children = $delete !== null
            ? [$delete, $s->spacer(), $cancel, $save]
            : [$s->spacer(), $cancel, $save];

        return $s->flex($children, justify: 'end');
    }
}
