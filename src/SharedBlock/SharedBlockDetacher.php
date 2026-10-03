<?php

declare(strict_types=1);

namespace Tbtop\Cms\SharedBlock;

use Tbtop\Cms\Layout\Events\LayoutSaved;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;

/**
 * Turns every reference to a shared block inside one container back into
 * an inline copy of its content, from outside the container's editor. The
 * rows keep id, order and active flag; a page save bumps lock_version
 * through PageObserver, so an editor with the page open gets the usual
 * conflict on their next save instead of silently re-pointing the row.
 */
final class SharedBlockDetacher
{
    /** @return int how many rows were detached */
    public static function detach(SharedBlock $block, Page | Layout $container): int
    {
        $id = (int) $block->getKey();
        $content = is_array($container->content) ? array_values($container->content) : [];
        $detached = 0;

        foreach ($content as $index => $row) {
            if (SharedBlocks::refOf($row) !== $id) {
                continue;
            }
            unset($row['shared']);
            $content[$index] = [...$row, 'data' => $block->data];
            $detached++;
        }

        if ($detached === 0) {
            return 0;
        }

        $container->content = $content;
        $container->save();
        if ($container instanceof Layout) {
            event(new LayoutSaved($container));
        }

        return $detached;
    }
}
