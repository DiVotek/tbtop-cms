# feat(seo): markdown versions of pages for AI agents

touches: page route, SEO settings tab, settings key, config key, Block and PageTypeInterface extension points, llms.txt links

> ⚠️ **Interface change** `PageTypeInterface` gains `toMarkdown(Page $page, MarkdownContext $ctx): ?string`. `BasePageType` implements it; a host type implementing the interface directly must add it.
> ⚠️ **Published config** a host `seo` section has no `markdown` key; the inline default applies (the editor's switch decides).

While an editor has it on (SEO settings → *For AI*), every live page also
answers as markdown at its path plus `.md` (`/about.md`, `/index.md` for the
home page) and to a request whose `Accept` ranks `text/markdown` above
`text/html`: frontmatter from the page SEO, heading, summary, body, every
block, the page type's addition (by default a category lists its children),
the notes for AI with the contacts, and the page's whole JSON-LD graph as a
`json` block. `Block::toMarkdown()` shapes a block's part (default: the text
of its translatable fields; `''` hides it); Hero and ContactForm implement it.
`Vary: Accept` and `Link` headers tie the HTML and markdown answers together,
and llms.txt links the `.md` versions while markdown is on. Never served for
drafts or while indexing is off.
