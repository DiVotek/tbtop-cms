# refactor: move CmsServiceProvider's boot wiring into Support/Wiring

touches: nothing visible; package boot order and every registration unchanged

`CmsServiceProvider` (597 lines) kept every `wire*()` step inline. They now
live in five classes under `src/Support/Wiring/`, grouped by what they wire:
`AdminWiring` (panel, `login` redirect, logout, link page search, locale
seeding), `ContentEventsWiring` (slug backfill, redirects, revisions, refs,
content cache), `NotificationWiring`, `ErrorPagesWiring` (404 log, error
pages) and `ScheduleWiring` (prunes, heartbeat). Method names and schedule
names are unchanged; docs now point at the new classes. `configurePackage()`
keeps the migration and command lists.

First of five splits from arti `spec-cms-tbtop-split-large-classes`.
