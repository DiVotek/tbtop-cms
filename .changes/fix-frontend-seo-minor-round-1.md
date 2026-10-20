# fix: layout cache, SEO audit, sitemap priority, 404 hits (minor-fix round 1)

touches: shared layout prop, SEO report/checklist, sitemap, form submit error, 404 hit recorder

- The cached layout (`cms.layout`) now uses `CmsCache::rememberLive()`, so a header/footer link to a scheduled page appears (or disappears) at the schedule transition instead of up to an hour later.
- The SEO report and `cms:seo:snapshot` leave out the error pages (404/500), as the sitemap does, and count pages scanned with the same filter.
- The SEO duplicate checks load `subtype`, so subtype title/description templates apply.
- A page with no slug in the default locale no longer gets the home page's sitemap priority (1.0).
- The visitor-facing "could not process your submission" error is translated (`forms.submit_failed`).
- The 404 hit counter works on a connection with a table prefix.
