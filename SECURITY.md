# Security Policy

## Supported versions

`tbtop/cms` is pre-1.0. Only the latest published `0.x` minor version receives
security fixes; older minors are not patched. Once the package reaches `1.0`,
this policy moves to standard semver support (fixes on the latest major, with
backports considered case by case).

## Reporting a vulnerability

Please report security vulnerabilities through GitHub's private vulnerability
reporting, not a public issue:

1. Go to the [Security tab](https://github.com/DiVotek/tbtop-cms/security) of
   this repository.
2. Click **"Report a vulnerability"** and fill in the advisory form.

If the **"Report a vulnerability"** button is not available (for example,
before the first public release), contact the maintainers
([@DiVotek](https://github.com/DiVotek)) directly through GitHub instead of
opening a public issue.

Reports are handled privately by the maintainers. You should expect an
acknowledgment within a few business days. Please do not open a public issue
for a suspected vulnerability — it gives potential attackers a head start
before a fix ships.

## Scope

This repository covers `tbtop/cms` itself: the page/block CMS core, its admin
pages, and the frontend rendering it ships. The CMS runs inside a host Laravel
application and depends on the `tbtop/admin` package for the admin DSL and
client. If you find an issue in `tbtop/admin` (the PHP DSL or the
`@tbtop/inertia-admin` client), please report it to
[DiVotek/tbtop](https://github.com/DiVotek/tbtop) instead.

Issues in the host application's own code, or in third-party dependencies not
maintained by this project, are outside this policy's scope.
