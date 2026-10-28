<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection\BuiltIn;

use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Collection\Collection;

/**
 * Built in: the site's email addresses, in the editor's order. The first
 * published one is the identity node's `email`; all of them are
 * SiteContacts::emails().
 */
class Emails extends Collection
{
    public const string KEY = 'emails';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('tbtop-cms::collections.emails.label');
    }

    #[\Override]
    public function singularLabel(): string
    {
        return __('tbtop-cms::collections.emails.singular');
    }

    public function fields(S $s): array
    {
        return [
            $s->text('address')->label(__('tbtop-cms::collections.emails.fields.address'))->placeholder('hello@example.com')->required()->rules('email|max:190'),
        ];
    }
}
