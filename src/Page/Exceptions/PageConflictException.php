<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Exceptions;

use DomainException;

/**
 * Thrown by PageForm::save() when the submitted form's lock_version no longer
 * matches the row in the database — someone else saved (or restored) the same
 * page after this form was loaded. Deliberately its own exception rather than
 * reusing Illuminate\Validation\ValidationException (the pattern PageForm
 * already uses for hierarchy/status DomainExceptions from PageObserver): a
 * conflict isn't a per-field validation error — lock_version isn't a visible
 * form field, so a field-keyed error would be unreachable in the UI — it's a
 * whole-record "someone else changed this" state that callers surface as a
 * notify(), not a form error banner (see PageEditPage::save() /
 * PageEditBlockTabs::handleAdd()/handleRemove()).
 */
class PageConflictException extends DomainException {}
