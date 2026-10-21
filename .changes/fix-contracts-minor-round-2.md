# fix: approved contract changes (minor-fix round 2)

touches: error pages, admin login, notifications, page slugs

- Error pages: a DB-backed error page (`Cms::errorResponse()`) now sends `page.body` like a normal page (its Body as HTML, `''` when empty); before, the key was missing.
- Admin login: after sign-in the user goes to the admin URL they were sent away from (session `url.intended`) instead of always the dashboard; a target outside the admin or on another host still falls back to the dashboard.
