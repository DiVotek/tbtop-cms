<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Types\ArticleType;

/** An article subtype that references the legal services a specialist provides. */
class SpecialistPageType extends ArticleType
{
    #[\Override]
    public function key(): string
    {
        return 'specialist';
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
        return [
            $s->pages('services')->label('Services')->of('article', 'legal_service'),
            $s->text('bio')->label('Bio'),
        ];
    }
}
