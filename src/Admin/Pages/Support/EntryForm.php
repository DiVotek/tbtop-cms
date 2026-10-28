<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Collection\Collection;
use Tbtop\Cms\Collection\Exceptions\EntryConflictException;
use Tbtop\Cms\Collection\Models\Entry;
use Tbtop\Cms\Support\LockedSave;
use Throwable;

/**
 * The entry form: the collection's own fields under their own names (the
 * registry reserves `status` and `lock_version`), plus the status switch.
 * Validation is the admin's: the form's field rules run before these.
 */
final class EntryForm
{
    /** @return list<mixed> */
    public static function fields(S $s, Collection $collection): array
    {
        return [
            ...$collection->fields($s),
            $s->select('status')->label(__('tbtop-cms::collections.fields.status'))->required()
                ->options(self::statusOptions())
                ->rules('in:' . Entry::DRAFT . ',' . Entry::PUBLISHED),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function statusOptions(): array
    {
        return [
            ['value' => Entry::PUBLISHED, 'label' => __('tbtop-cms::collections.status.published')],
            ['value' => Entry::DRAFT, 'label' => __('tbtop-cms::collections.status.draft')],
        ];
    }

    /** @return array<string, mixed> */
    public static function recordValues(Entry $entry): array
    {
        return [...$entry->data, 'status' => $entry->status, 'lock_version' => $entry->lock_version];
    }

    /**
     * New entries go last in the editor's order.
     *
     * @param  array<string, mixed>  $form
     */
    public static function create(Collection $collection, array $form): Entry
    {
        $entry = new Entry([
            'collection' => $collection->key(),
            'data' => self::data($collection, $form),
            'status' => self::status($form),
            'sort_order' => (int) Entry::query()->of($collection->key())->max('sort_order') + 1,
        ]);
        $entry->save();

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $form
     *
     * @throws EntryConflictException when the form's lock_version is behind the row
     */
    public static function update(Collection $collection, Entry $entry, array $form): Entry
    {
        $expected = array_key_exists('lock_version', $form) ? (int) $form['lock_version'] : null;
        if ($expected !== null && $expected !== (int) $entry->lock_version) {
            throw new EntryConflictException("Entry #{$entry->getKey()} was changed since this form was loaded.");
        }

        try {
            $entry->expectedLockVersion = $expected;
            LockedSave::run(
                static fn (): bool => $entry->fill(['data' => self::data($collection, $form, $entry->data), 'status' => self::status($form)])->save(),
                static fn (Throwable $e): EntryConflictException => new EntryConflictException("Entry #{$entry->getKey()} hit a lock while saving.", 0, $e),
            );
        } finally {
            $entry->expectedLockVersion = null;
        }

        return $entry;
    }

    /**
     * The collection's fields from the form; a value the form did not post
     * keeps what is stored.
     *
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private static function data(Collection $collection, array $form, array $stored = []): array
    {
        $data = [];
        foreach (S::normalizeChildren($collection->fields(new S)) as $field) {
            if (! $field instanceof Field) {
                continue;
            }
            if (array_key_exists($field->name, $form)) {
                $data[$field->name] = $form[$field->name];
            } elseif (array_key_exists($field->name, $stored)) {
                $data[$field->name] = $stored[$field->name];
            }
        }

        return $data;
    }

    /** @param  array<string, mixed>  $form */
    private static function status(array $form): string
    {
        return ($form['status'] ?? null) === Entry::PUBLISHED ? Entry::PUBLISHED : Entry::DRAFT;
    }
}
