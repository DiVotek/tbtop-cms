{{--
    Vite-free root view used only by ServerHeadTest via
    config(['tbtop-cms.render.root_view' => 'server-head-test']).

    A plain (non-X-Inertia) GET renders whichever root view Inertia is
    configured with, which means it also renders workbench/resources/views/
    app.blade.php's `@vite` directive — and workbench/public/build/manifest.json
    is gitignored (see /workbench/public/ in .gitignore), so it exists locally
    (after `bun run build`) but not in a fresh CI checkout. Asserting on
    server-rendered head tags through the real app.blade.php would pass
    locally and throw ViteManifestNotFoundException on CI (this is exactly
    what broke cms#98's CI). This view exercises the same
    @include('tbtop-cms::head') + @inertiaHead path without @vite, so the
    manifest never enters the picture.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    @include('tbtop-cms::head')
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
