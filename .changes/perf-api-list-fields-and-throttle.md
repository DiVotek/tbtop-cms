# pages API: one field mapping per list, a rate limit, wider tests

touches: `GET /api/cms/v1/pages`, new config key `tbtop-cms.api.throttle`

`GET /pages` mapped the page type's DSL fields (RuleWalker, the admin-locale
switch) once per row, up to 100 times a request; it now maps each
(type, subtype) once per list.

Every API route is now rate limited by `tbtop-cms.api.throttle` (`max,minutes`,
default `'60,1'`, counted per token user; `''` turns it off). A host that calls
the API more often than 60 times a minute per token raises the limit there.

Tests now cover publish scheduling and its refusals, a slug change on a
published page (and its redirect), select/media/repeater shape rules, the
RestrictedHtml refusals, a refused media import and `cms:api-token` refusing
ids that are not categories.
