# fix: approved contract changes (minor-fix round 2)

touches: error pages, notifications, page slugs

- Error pages: a DB-backed error page (`Cms::errorResponse()`) now sends `page.body` like a normal page (its Body as HTML, `''` when empty); before, the key was missing.
