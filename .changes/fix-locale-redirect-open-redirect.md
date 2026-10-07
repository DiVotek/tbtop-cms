# fix(i18n): keep the default-locale redirect on this host

touches: SetLanguage middleware (default-locale prefix redirect)

Security: `/en//evil.example` (any default-locale prefix followed by an empty
segment, also `///` and `%2F%2F`) answered 301 to `//evil.example`, a
protocol-relative URL that browsers follow to another host — an open redirect
usable for phishing links on the site's own domain. Empty segments are now
dropped before the clean URL is built, so the redirect always stays on the
site (`/evil.example`, then the usual 404).

Unchanged: non-default locales and paths without empty segments; the query
string is still carried over.

Tests: `tests/Frontend/RouteResolverTest.php` (three empty-segment shapes).

**Read by eye:**
- `src/Frontend/Http/Middleware/SetLanguage.php`
