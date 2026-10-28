<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection\BuiltIn;

use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Collection\Collection;

/**
 * Built in: the site's phone numbers, in the editor's order. The first
 * published one is the identity node's `telephone`; all of them are
 * SiteContacts::phones().
 */
class Phones extends Collection
{
    public const string KEY = 'phones';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('tbtop-cms::collections.phones.label');
    }

    #[\Override]
    public function singularLabel(): string
    {
        return __('tbtop-cms::collections.phones.singular');
    }

    public function fields(S $s): array
    {
        return [
            $s->text('number')->label(__('tbtop-cms::collections.phones.fields.number'))->placeholder('+380 44 000 00 00')->required()->rules('max:40'),
        ];
    }
}
