# fix: notification render isolation, per-locale preview links, breadcrumbs without non-live ancestors

touches: notifications (send, channel driver, Delivery, NotificationFailed), submission notifier and retrier, page edit status bar, frontend breadcrumbs

> ⚠️ **Contract** `Notifications::send()` no longer throws when a template fails to render: the pair gets a `Failed` log row, a `Failed` `Delivery` and a `NotificationFailed` event, and an error-level `notifications.template_render_failed` log entry. Queued senders (storefront `SendsOrderNotification`) complete instead of being retried.
> ⚠️ **Contract** `Delivery` and `NotificationFailed` gain a trailing optional `DeliveryStage $stage` (`Render` | `Send`, default `Send`). `NotificationChannelDriver::recordFailure()` is new and public.
> ⚠️ **Contract** `Breadcrumbs::forPage()` / `ancestors()` (the `breadcrumbs` prop, JSON-LD `BreadcrumbList`, `{parent}`, `Cms::breadcrumbs()`, admin SERP/OG previews, `SeoChecklist`, `SeoAudit`) leave out non-live ancestors and a non-live site root.
> ⚠️ **Contract** New translation keys `tbtop-cms::notifications.errors.render`, `tbtop-cms::notifications.channels.{mail,telegram,bell}`, `tbtop-cms::pages.preview.no_slug` (en, uk). The preview-link modal's field `previewUrl` becomes `previewUrl_{locale}`.

- **B5.** A built-in notification view that threw stopped the whole fan-out
  and left the submission `Processing` for good. Now each pair's render is
  isolated: the pairs of the broken format fail, the others deliver, and the
  submission ends `Failed` with `Couldn't render the Email notification: …`
  (distinct errors joined once). Any other exception after the claim, in
  `notify()` or anywhere in `retry()`, ends the row `Failed` with its message
  (claim released in `finally`), is reported as is, with `submission_id` in
  the log context, and is not rethrown: the admin retry shows a toast,
  not a 500.
- **B11.** The preview link signed only the default locale, and a page
  without a default-locale slug got a link to `/`. The modal now lists one
  signed link per frontend-active locale with a saved slug; with none it shows
  "Save a URL slug to get a preview link." Error pages get no action.
- **B12.** A draft category still showed in the crumbs, `BreadcrumbList` and
  `{parent}` of its published articles. `Breadcrumbs` now filters non-live
  ancestors and a non-live root; `AncestorChain`, `PageResolver` and the
  sitemap are untouched. Only the root's id is memoized; its liveness is read
  fresh, because the route keeps the controller (and its `Breadcrumbs`) for
  longer than a request.

Rejected alternatives: aborting the whole fan-out on a render error (one bad
view would silence every channel); a link only for the locale being edited
(the status bar does not know it); filtering in `AncestorChain` (hierarchy
checks and starter blocks need draft ancestors — `PageHierarchyTest` and
`StarterBlocksTest` fail when it is moved there).

Tests: `tests/Notifications/NotificationsTest.php` (render failure per pair,
override + built-in both throwing), `tests/Form/SubmissionNotifierTest.php`
and `tests/Form/SubmissionRetrierTest.php` (render prefix, non-render
exception, admin retry toast), `tests/Admin/PageEditPageTest.php` (per-locale
links, uk-only, no slug, error page), `tests/Frontend/BreadcrumbsTest.php`
(new: draft category, expired root, all filtered).

**Read by eye:**
- `src/Notifications/Notifications.php`
- `src/Notifications/NotificationChannelDriver.php`
- `src/Form/SubmissionNotifier.php`
- `src/Form/SubmissionRetrier.php`
- `src/Admin/Pages/Support/PageEditStatusBar.php`
- `src/Frontend/Breadcrumbs.php`

**Assumptions:**
- A missing slug for a frontend-active locale only comes from data drift
  (every save writes one per locale); tests delete slug rows to reach it.
- The error text may include the view path from Blade's `ViewException`;
  only admins see it.
- The storefront queue claim is not verified here (storefront is not
  installed in this repo).
