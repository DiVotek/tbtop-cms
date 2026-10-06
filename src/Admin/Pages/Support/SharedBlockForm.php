<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Facades\Validator;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\SharedBlock\Events\SharedBlockSaved;
use Tbtop\Cms\SharedBlock\Exceptions\SharedBlockConflictException;
use Tbtop\Cms\SharedBlock\Models\SharedBlock;

/**
 * Create takes a name and a kind; the content is edited afterwards on
 * SharedBlockEditPage with the block's own form, prefixed `data_*` so the
 * `name` field and a block field called `name` cannot collide.
 */
final class SharedBlockForm
{
    public const string PREFIX = 'data_';

    /** @return list<mixed> */
    public static function createFields(S $s): array
    {
        return [
            $s->text('name')->label(__('tbtop-cms::shared-blocks.fields.name'))->required(),
            $s->select('kind')->label(__('tbtop-cms::shared-blocks.fields.kind'))->required()
                ->options(array_map(
                    static fn (string $kind, string $label): array => ['value' => $kind, 'label' => $label],
                    array_keys(self::kindOptions()),
                    self::kindOptions(),
                )),
        ];
    }

    /** @return list<mixed> the block's form fields, renamed under the data prefix */
    public static function dataFields(S $s, SharedBlock $block): array
    {
        $registry = app(BlockRegistry::class);
        if (! $registry->has($block->kind)) {
            return [];
        }

        return array_map(
            static fn ($field) => PrefixedField::rename($field, self::PREFIX . $field->name, null),
            $registry->get($block->kind)->form($s),
        );
    }

    /** @return array<string, mixed> record values for the edit form */
    public static function recordValues(SharedBlock $block): array
    {
        $values = ['name' => $block->name, 'lock_version' => $block->lock_version];
        $registry = app(BlockRegistry::class);
        if (! $registry->has($block->kind)) {
            return $values;
        }

        foreach ($registry->get($block->kind)->form(new S) as $field) {
            if (array_key_exists($field->name, $block->data)) {
                $values[self::PREFIX . $field->name] = $block->data[$field->name];
            }
        }

        return $values;
    }

    /**
     * A shared block born from a page row: name and kind are the caller's,
     * `$data` is the row's content in stored shape (per-locale maps intact).
     *
     * @param  array<string, mixed>  $data
     */
    public static function createFromRow(string $name, string $kind, array $data): SharedBlock
    {
        $block = SharedBlock::query()->create(['name' => $name, 'kind' => $kind, 'data' => $data]);
        event(new SharedBlockSaved($block));

        return $block;
    }

    /** @param  array<string, mixed>  $form */
    public static function create(array $form): SharedBlock
    {
        $valid = Validator::make($form, [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string', 'in:' . implode(',', array_keys(self::kindOptions()))],
        ])->validate();

        $block = SharedBlock::query()->create([...$valid, 'data' => []]);
        event(new SharedBlockSaved($block));

        return $block;
    }

    /**
     * @param  array<string, mixed>  $form
     *
     * @throws SharedBlockConflictException when the form's lock_version is behind the row
     */
    public static function update(array $form, SharedBlock $block): SharedBlock
    {
        // Checked before anything is written; a caller without the key
        // (none in the admin) skips it, as PageForm::save() does.
        if (array_key_exists('lock_version', $form) && (int) $form['lock_version'] !== (int) $block->lock_version) {
            throw new SharedBlockConflictException("Shared block #{$block->getKey()} was changed since this form was loaded.");
        }

        $valid = Validator::make($form, ['name' => ['required', 'string', 'max:255']])->validate();

        $data = [];
        $registry = app(BlockRegistry::class);
        if ($registry->has($block->kind)) {
            foreach ($registry->get($block->kind)->form(new S) as $field) {
                $key = self::PREFIX . $field->name;
                if (array_key_exists($key, $form)) {
                    $data[$field->name] = $form[$key];
                }
            }
        } else {
            $data = $block->data;
        }

        $block->fill(['name' => $valid['name'], 'data' => $data])->save();
        event(new SharedBlockSaved($block));

        return $block;
    }

    /** @return array<string, string> kind => label */
    public static function kindOptions(): array
    {
        $options = [];
        foreach (app(BlockRegistry::class)->all() as $block) {
            $options[$block->kind()] = $block->label();
        }
        asort($options);

        return $options;
    }
}
