# feat(seo): llms.txt and robots.txt Content-Signal

touches: new route, SEO settings tab, settings keys, config keys, robots.txt, health check

> ⚠️ **Constructor change** `RobotsTxt` takes a `ContentSignals` as well; resolve it from the container.
> ⚠️ **Owned directive** `Content-Signal` joins the robots.txt directives the site writes itself: an existing `seo.robots_extra` line starting with it is dropped on render and rejected on the next save.
> ⚠️ **Published config** a host `seo` section has no `llms_txt` keys; the inline defaults apply (route on, no host sections).

`GET /llms.txt` (llmstxt.org) lists the site for AI agents: site name,
summary, the editor's notes, contacts (phones, emails, socials, address,
opening hours) and one section per base page type with the live indexable
pages (articles newest first, others home first then by depth), then host
sections from `tbtop-cms.seo.llms_txt.sections` (`LlmsTxtSection`) and an
`## Optional` sitemap link. Off until the editor turns it on in SEO settings
→ *For AI*; 404 while indexing is off; cached per origin like the sitemap.

The same tab can add `Content-Signal: search=yes, ai-input=yes, ai-train=no`
(contentsignals.org) to the robots.txt `User-agent: *` group, each signal
switchable. Off by default: it states the owner's terms.

A `llms_txt` health check warns when a static `public/llms.txt` shadows the
route.
