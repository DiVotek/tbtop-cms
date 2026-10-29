<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Tbtop\Cms\Page\Models\Page;
use Traversable;

/**
 * Resolved entries of one collection, each `{id, ...fields}`, in the
 * editor's order. Immutable: every filter returns a new list. Filters run in
 * memory on the cached collection (CollectionEntries).
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final readonly class EntryList implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  string|null  $relatedField  the collection's `pages` field relatedTo() reads
     */
    public function __construct(
        private array $entries,
        private ?string $relatedField = null,
    ) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->entries;
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        return $this->entries[0] ?? null;
    }

    public function take(int $count): self
    {
        return $this->with(array_slice($this->entries, 0, max(0, $count)));
    }

    /** The newest first, by creation: the order "latest N" shows. */
    public function latest(?int $count = null): self
    {
        $entries = $this->entries;
        usort($entries, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);

        return $count === null ? $this->with($entries) : $this->with(array_slice($entries, 0, max(0, $count)));
    }

    /**
     * Entries whose related `pages` field (Collection::relatedField()) picks
     * $page. Empty for a null page or a collection without such a field.
     */
    public function relatedTo(Page | int | null $page): self
    {
        $id = $page instanceof Page ? (string) $page->getKey() : ($page === null ? null : (string) $page);
        $field = $this->relatedField;
        if ($id === null || $field === null) {
            return $this->with([]);
        }

        return $this->with(array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => in_array($id, array_map(
                static fn (mixed $ref): ?string => is_array($ref) && isset($ref['id']) ? (string) $ref['id'] : null,
                is_array($entry[$field] ?? null) ? $entry[$field] : [],
            ), true),
        )));
    }

    /**
     * The entries with these ids, in the order given; ids of drafts and
     * deleted entries are skipped.
     *
     * @param  iterable<int|string>  $ids
     */
    public function only(iterable $ids): self
    {
        $byId = array_column($this->entries, null, 'id');
        $out = [];
        foreach ($ids as $id) {
            $key = is_numeric($id) ? (int) $id : null;
            if ($key !== null && isset($byId[$key])) {
                $out[$key] = $byId[$key];
            }
        }
        $out = array_values($out);

        return $this->with($out);
    }

    /** Entries whose resolved `$field` equals $value, or holds it when the field is a list. */
    public function where(string $field, mixed $value): self
    {
        return $this->with(array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => is_array($entry[$field] ?? null) && array_is_list($entry[$field])
                ? in_array($value, $entry[$field], false)
                : ($entry[$field] ?? null) == $value,
        )));
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** @return ArrayIterator<int, array<string, mixed>> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entries);
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return $this->entries;
    }

    /** @param  list<array<string, mixed>>  $entries */
    private function with(array $entries): self
    {
        return new self($entries, $this->relatedField);
    }
}
