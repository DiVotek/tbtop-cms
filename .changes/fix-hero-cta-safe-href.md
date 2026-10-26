# fix: Hero CTA link refuses script URLs

touches: `HeroBlock` resolve and form rules; new named rule `safe_href`

`ctaHref` is a free-text field, so `javascript:alert(1)` was saved and sent
to the frontend as the button's href. The new `safe_href` rule
(`Tbtop\Cms\Link\SafeHref`) accepts a relative URL or an http(s), mailto,
tel or `ExternalSchemes` link, read the way a browser parses it (tabs and
newlines removed). The Hero form rejects anything else at save, and
`resolve()` drops a stored unsafe link (`cta: null`), so rows saved before
the fix stop rendering it. The Node port gets the same allowlist in its own PR (#307).
