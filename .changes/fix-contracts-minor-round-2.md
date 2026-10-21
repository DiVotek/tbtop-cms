# fix: approved contract changes (minor-fix round 2)

touches: SEO alternates, form submissions, error pages, admin login, notifications, page slugs

- Error pages: a DB-backed error page (`Cms::errorResponse()`) now sends `page.body` like a normal page (its Body as HTML, `''` when empty); before, the key was missing.
- Admin login: after sign-in the user goes to the admin URL they were sent away from (session `url.intended`) instead of always the dashboard; a target outside the admin or on another host still falls back to the dashboard.
- Bell: a notification's action button reads "View submission" for a form submission (new `FormSubmissionPayload::$actionLabel`, also a `{{ $actionLabel }}` template variable) and "Open" (`tbtop-cms::notifications.bell.open`) for a host payload without `actionLabel`, instead of the raw URL.
- Notification settings: the built-in channels' `label()` is now the short translated name (`tbtop-cms::notifications.channels.{mail,telegram,bell}`: Email / Telegram / Bell), so the channel picker no longer shows "Mail transport" / "Notifications".
- Pages: an edit-form save that changes only the slug now raises `PageSaved` (content cache bump, fresh sitemap); `PageSlugWriter::sync()` returns `bool` (whether a slug row changed) instead of `void`.
- Notifications: `Rendered::$subject` is now plain text for every channel (`TemplateRenderer` decodes it once); a host channel no longer gets `&amp;`/`&quot;`, and mail/bell no longer decode it themselves.
- SEO: the head's hreflang `alternates` (and with them `xDefault` and `ogLocaleAlternates`) leave out a locale whose canonical override points elsewhere, the same rule the sitemap already applied; both now read one list (`SeoAlternates::forPage()`, new `SeoResolver::alternates()`).
- Notification types: `TypeDefinition::label()` takes `string|Closure`; a closure is resolved per `labelValue()` call, so a type label follows the admin's locale. The built-in `form.submission` type uses one (its settings card title was stuck in `app.locale`).
- Form submissions: a submission stuck in Pending/Processing (its worker died) can be retried once untouched for 15 minutes (`SubmissionRetrier::STALE_AFTER_MINUTES`); the claim checks the same age, so a live send is never taken over. Both the inbox row action and the detail page show Retry for such rows (`SubmissionRetrier::isRetryable()`, a hidden `retryable` list column).
