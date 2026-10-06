<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Page\Types\BasePageType;

/**
 * A subtype of EventPageType adding `artist`, with a computed `billing`.
 * requiresParent() is true on purpose: tree rules are read from the base,
 * so a concert page without a parent must still save.
 */
class ConcertPageType extends BasePageType
{
    public function key(): string
    {
        return 'concert';
    }

    #[\Override]
    public function base(): string
    {
        return 'event';
    }

    #[\Override]
    public function requiresParent(): bool
    {
        return true;
    }

    /** @return list<Field> */
    #[\Override]
    public function fields(S $s): array
    {
        return [$s->text('artist')->label('Artist')];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    #[\Override]
    public function resolveFields(array $input, ResolveContext $ctx): array
    {
        $fields = parent::resolveFields($input, $ctx);

        return [...$fields, 'billing' => is_string($fields['artist']) ? strtoupper($fields['artist']) . ' LIVE' : null];
    }
}
