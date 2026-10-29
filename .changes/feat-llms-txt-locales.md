# feat(seo): llms.txt per locale

touches: page route, llms.txt, HTML Link header

Every frontend locale other than the default now has its own llms.txt at
`/{locale}/llms.txt` (`/uk/llms.txt`), in that locale: its summary, notes,
contact labels and page links. `/en/llms.txt` for the default locale 301s to
`/llms.txt`. Each file's `## Optional` list links the file in the other
frontend languages. While llms.txt is on, an HTML page answer names its
locale's file in `Link: <…/llms.txt>; rel="describedby"`, now also while
markdown versions are off.
