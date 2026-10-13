# fix(pages): page-name search ignores case on MySQL and in Cyrillic

touches: `link` field page picker, `$s->pages()` field search

On MySQL the page pickers matched names case-sensitively: `name->en like ?`
compares the unquoted JSON value, which carries `utf8mb4_bin`, so "cont" missed
"Contact". SQLite folds ASCII only, so "кон" missed "Контакти" on both drivers.
Both pickers now go through `PageNameSearch`: the term is lower-cased with
`mb_strtolower()`, the column with the driver's `LOWER()`; on SQLite, whose
`LOWER()` is ASCII-only, through a `tbtop_lower` function registered on the
connection.

- Out of scope: the parent picker (`relation('parent_id')`) and the Pages list
  search run through tbtop/admin (`RelationSearchResponder`, table search) and
  keep their current matching; the parent-picker test stays skipped on MySQL.
