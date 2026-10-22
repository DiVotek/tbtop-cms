# perf(blocks): count shared-block usage in SQL on sqlite and mysql

touches: shared blocks list (card counts, delete guard), shared block editor
usage table, page editor add-block chooser

Shared-block usage was found by loading the `content` of every page
(including trashed) and every layout and scanning it in PHP, on every render
of these screens. On SQLite and MySQL the database now answers from the JSON:
the counts are one `count(distinct container)` per container table grouped by
the pointer, joining `json_each` (SQLite) or `json_table` (MySQL); the usage
table and the delete guard load only the pages and layouts that place the
block, in the same order as before. The shared blocks list reads counts only.

- The placement rule is unchanged: a content row whose `shared` is an int or a
  digit-only string; `"70"` is not `7`, a `7` inside block data is not a
  pointer, a container placing a block twice counts once, trashed pages and
  layout slots count, revisions do not.
- Any other driver (pgsql, mariadb, …) keeps the PHP scan.
- MySQL needs 8.0.17+ (`json_table`); the MySQL branch reads top-level rows of
  a JSON array, which is how every writer stores `content`.

Unchanged: counts, the usage table, the delete guard and their texts.

Tests: `tests/SharedBlock/SharedBlockUsageTest.php` (every pointer form, once
per container, on the list card count and the usage table; run on SQLite and
`composer test:mysql`).

**Read by eye:**
- `src/SharedBlock/SharedBlockUsage.php`
