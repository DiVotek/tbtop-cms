<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection\BuiltIn;

use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Collection\Collection;

/**
 * Built in: the site's profile links. Every published entry is an identity
 * `sameAs` link and a SiteContacts::socials() row.
 */
class Socials extends Collection
{
    public const string KEY = 'socials';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('tbtop-cms::collections.socials.label');
    }

    #[\Override]
    public function singularLabel(): string
    {
        return __('tbtop-cms::collections.socials.singular');
    }

    #[\Override]
    public function titleField(): string
    {
        return 'name';
    }

    #[\Override]
    public function deletable(): bool
    {
        return false;
    }

    #[\Override]
    public function icon(): string
    {
        return 'share-2';
    }

    #[\Override]
    public function navigationGroup(): string
    {
        return 'settings';
    }

    #[\Override]
    public function navigationOrder(): int
    {
        return 22;
    }

    public function fields(S $s): array
    {
        return [
            $s->text('name')->label(__('tbtop-cms::collections.socials.fields.name'))->placeholder('Telegram')->rules('nullable|max:40'),
            $s->text('label')->label(__('tbtop-cms::collections.socials.fields.label'))->placeholder('TG')->required()->rules('max:8'),
            $s->text('href')->label(__('tbtop-cms::collections.socials.fields.href'))->required()->rules('url|max:255'),
        ];
    }
}
