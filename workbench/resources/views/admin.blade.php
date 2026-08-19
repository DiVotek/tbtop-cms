{{--
    Root view for the CMS panel (CmsPanel::configure() -> rootView('admin')).
    A real host gets a dedicated admin entry from `admin:install`; the
    workbench bundles the panel and its sample frontend into one app.tsx on
    purpose, so this view only differs from app.blade.php by existing.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @viteReactRefresh
    @vite(['workbench/resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
