<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

/** Placeable on the `concert` subtype only; reads the page's `artist` type field. */
class ConcertTicketBlock extends Block
{
    public function kind(): string
    {
        return 'ConcertTicket';
    }

    /** @return list<string> */
    #[\Override]
    public function placements(): array
    {
        return ['concert'];
    }

    /** @return list<Field> */
    public function form(S $s): array
    {
        return [$s->text('price')->label('Price')];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{price: mixed, artist: mixed}
     */
    #[\Override]
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['price' => $input['price'] ?? null, 'artist' => $ctx->field('artist')];
    }
}
