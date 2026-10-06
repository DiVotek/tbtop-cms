<?php

declare(strict_types=1);

namespace Tbtop\Cms\SharedBlock\Exceptions;

use DomainException;

/** The edit form's lock_version is behind the row: someone else saved the shared block meanwhile. */
class SharedBlockConflictException extends DomainException {}
