# test: stop the prefixed-connection 404 test from hanging the MySQL suite

touches: nothing visible; tests only

The 404 hit-count test from #293 switched `database.default` to an SQLite
connection and left it there. RefreshDatabase then rolled back that connection
instead of MySQL, so the suite's open MySQL transaction kept its locks and the
next `migrate` in the same worker waited on them forever (the `Test (MySQL)`
job on #294 ran into the 6-hour timeout). The test now restores the default
connection in a `finally`.
