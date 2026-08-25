<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

enum SubmissionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Handled = 'handled';
    case Failed = 'failed';
}
