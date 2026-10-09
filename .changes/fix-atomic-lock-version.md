# fix(pages): the lock_version compare and bump are one statement

touches: page editor save and its block-tab actions, shared block editor save,
every other page / shared block write (revision restore, detach, starter sync,
untrash)

Optimistic locking was check-then-save: the form compared the version it had
read, then the model wrote `loaded + 1`. Two saves loaded at one version that
interleaved between read and write both passed, and the later one silently
overwrote the first. Now a form save claims the row with one conditional
`UPDATE … SET lock_version = lock_version + 1 WHERE id = ? AND lock_version = ?`
inside one transaction with the content write and its side effects; the
loser gets the existing conflict toast and leaves no revision, slug, ref,
activity row or `PageSaved` / `SharedBlockSaved`.

- A form save that changes nothing only compares and keeps the version.
- A claim on a page that is gone or trashed answers 404.
- Lock-busy, lock-wait-timeout and deadlock errors become the conflict toast
  at the outermost transaction of the save (including Share).
- Non-form writers bump relatively in SQL and never conflict.

> ⚠️ **Behavior change** `SharedBlockSaved` from the shared block editor's
> save is dispatched after the save transaction commits (`DB::afterCommit`),
> as `PageSaved` already is. A host listener no longer runs inside the write.

> ⚠️ **Behavior change** A page form save whose only change is the slug now
> bumps `lock_version`, so other forms open on that page go stale and get the
> conflict toast on their next save.

> ⚠️ **Behavior change** A page save, with its revision, slug cascade, refs
> and activity row, is one transaction: a failure in a later step rolls the
> whole save back instead of leaving a half-written page.

Unchanged: conflict texts, the hidden `lock_version` field, a payload without
the key skips the check.

Tests: `tests/Admin/LockVersionRaceTest.php` (a write injected between the
save's read and its write, pages and shared blocks).

**Read by eye:**
- `src/Support/LockVersion.php`, `src/Support/LockedSave.php`
- `src/Page/Observers/PageObserver.php`, `src/SharedBlock/Models/SharedBlock.php`
