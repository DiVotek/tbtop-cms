{{--
    Third layer for a 5xx: no DB read, no Inertia, no settings — rendering
    the 5xx page itself is what's failing, so this view must not depend on
    anything that could fail the same way. See ErrorResponse::render5xx().
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>{{ __('tbtop-cms::errors.5xx.heading') }}</title>
</head>
<body>
    <h1>{{ __('tbtop-cms::errors.5xx.heading') }}</h1>
</body>
</html>
