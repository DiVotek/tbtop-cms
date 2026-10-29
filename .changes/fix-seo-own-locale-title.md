# fix: the meta title prefers the locale's own name over the default language's title

touches: `<title>`, og:title, JSON-LD names, SEO checklist and report

The own title cascade (`seo.title` → `title` → `name`) used to fall back to
the default language at each rung, so a page with a Russian name and only a
Ukrainian title rendered the Ukrainian title on `/ru/…`. The whole cascade
now runs in the requested locale first and reaches the default language only
when this locale has neither a title nor a name. `seo.title` itself still has
no language fallback; the description cascade already followed this order.
