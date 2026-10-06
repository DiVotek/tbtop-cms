<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Refs;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Tbtop\Cms\Page\Models\Page;

/**
 * The type/subtype pairs a pages field accepts, as `type` or `type/subtype`
 * strings: the form the field keeps in its options (PrefixedField::rename()
 * replays them) and the parameters of its `page_ref` rule. No pair: any page.
 */
final readonly class PageTypeFilter
{
    /** @param  list<string>  $pairs */
    private function __construct(public array $pairs) {}

    /** @param  array<array-key, mixed>  $pairs */
    public static function of(array $pairs): self
    {
        return new self(array_values(array_filter(
            array_map(static fn (mixed $pair): string => is_string($pair) ? trim($pair) : '', $pairs),
            static fn (string $pair): bool => $pair !== '',
        )));
    }

    public static function pair(string $type, ?string $subtype): string
    {
        if ($type === '' || str_contains($type, '/') || str_contains($type, ',')) {
            throw new InvalidArgumentException("Invalid page type key \"{$type}\".");
        }
        if ($subtype !== null && ($subtype === '' || str_contains($subtype, '/') || str_contains($subtype, ','))) {
            throw new InvalidArgumentException("Invalid page subtype key \"{$subtype}\".");
        }

        return $subtype === null ? $type : "{$type}/{$subtype}";
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->pairs === []) {
            return $query;
        }

        return $query->where(function (Builder $any): void {
            foreach ($this->pairs as $pair) {
                [$type, $subtype] = array_pad(explode('/', $pair, 2), 2, null);
                $any->orWhere(static function (Builder $one) use ($type, $subtype): void {
                    $one->where('type', $type);
                    if ($subtype !== null) {
                        $one->where('subtype', $subtype);
                    }
                });
            }
        });
    }
}
