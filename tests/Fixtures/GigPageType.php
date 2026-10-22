<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Page\Types\BasePageType;

/** A subtype of EventPageType whose own resolveFields() declares its shape, so its page.fields are typed from the docblock. */
class GigPageType extends BasePageType
{
    public function key(): string
    {
        return 'gig';
    }

    #[\Override]
    public function base(): string
    {
        return 'event';
    }

    /** @return list<Field> */
    #[\Override]
    public function fields(S $s): array
    {
        return [$s->text('band')->label('Band')];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{band: string|null, soldOut: bool}
     */
    #[\Override]
    public function resolveFields(array $input, ResolveContext $ctx): array
    {
        $band = is_string($input['band'] ?? null) && $input['band'] !== '' ? $input['band'] : null;

        return ['band' => $band, 'soldOut' => $band === null];
    }
}
