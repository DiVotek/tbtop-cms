# chore: bump tbtop/admin and @tbtop/inertia-admin to ^0.7.1

touches: admin sessions; a session that no longer matches the signed-in
user's password hash is logged out

Admin 0.7 ends the panel auth stack with `AuthenticateSession`, so changing an
admin's password signs out their other sessions. Tests that switch between two
admins inside one session now flush the session before the second
`actingAs`. The cms keeps its own `tbtop.cms.logout` route and
`ShareAdminAuth` prop for now, although admin 0.7 ships a panel logout route
and shares `tbtop.user` itself.
