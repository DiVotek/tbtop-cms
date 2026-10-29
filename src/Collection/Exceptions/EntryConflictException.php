<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection\Exceptions;

use DomainException;

/** The edit form's lock_version is behind the row: someone else saved the entry meanwhile. */
class EntryConflictException extends DomainException {}
