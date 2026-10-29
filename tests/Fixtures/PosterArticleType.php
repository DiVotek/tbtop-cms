<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Types\ArticleType;

/**
 * An article subtype with one field of each shape the pages API checks
 * (select, media, repeater, richtext). It counts how often its form is
 * built, so a test can tell whether a list maps it once or per row.
 */
class PosterArticleType extends ArticleType
{
    public static int $fieldsBuilt = 0;

    #[\Override]
    public function key(): string
    {
        return 'poster';
    }

    #[\Override]
    public function base(): string
    {
        return 'article';
    }

    /** @return list<Field> */
    #[\Override]
    public function fields(S $s): array
    {
        self::$fieldsBuilt++;

        return [
            $s->select('tone')->options([['value' => 'calm', 'label' => 'Calm'], ['value' => 'loud', 'label' => 'Loud']]),
            $s->media('poster'),
            $s->media('gallery')->multiple(),
            $s->repeater('facts')->fields([
                $s->text('label'),
            ]),
            $s->richtext('aside'),
        ];
    }
}
