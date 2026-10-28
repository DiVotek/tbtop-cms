<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection;

use Tbtop\Cms\Admin\Fields\CollectionField;
use Tbtop\Cms\Block\ResolveContext;

/** Resolves a block's `collection` field value to the entries the site shows (FormResolver). */
final readonly class EntryPicker
{
    public function __construct(private CollectionEntries $entries) {}

    /** @return list<array<string, mixed>>|array<string, mixed>|null */
    public function pick(CollectionField $field, mixed $value, ResolveContext $ctx): ?array
    {
        $list = $this->entries->of($field->collectionKey(), $ctx->locale);
        $picked = CollectionValue::from($value, $field->allowedModes());

        $list = match ($picked->mode) {
            PickMode::All => $list,
            PickMode::Latest => $list->latest($picked->limit),
            PickMode::Related => $list->relatedTo($ctx->page),
            PickMode::Manual => $list->only($picked->ids),
        };

        $cap = $field->limitCap();
        if ($cap !== null) {
            $list = $list->take($cap);
        }

        return $field->isSingle() ? $list->first() : $list->all();
    }
}
