<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api\Fields;

/** The kinds `GET /types/{type}/fields` names. A type field of any other kind is not part of the API: neither described nor accepted. */
enum FieldKind: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Richtext = 'richtext';
    case Tags = 'tags';
    case Select = 'select';
    case Media = 'media';
    case Ref = 'ref';
    case Repeater = 'repeater';
    case Blocks = 'blocks';
}
