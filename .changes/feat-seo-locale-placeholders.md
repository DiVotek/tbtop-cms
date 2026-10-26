# feat(seo): per-locale SEO placeholders in the page editor

touches: page editor SEO tab (meta title/description, og title/description
placeholders)

The computed placeholders used to resolve only the default content locale,
so every language tab showed the default language's value. They now resolve
once per editor locale and ship as a locale map (tbtop/admin 0.7.2), so each
tab hints what that locale renders when the field is left blank. A new,
unsaved page still gets empty placeholders.
