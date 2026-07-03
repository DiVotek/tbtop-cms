# tbtop/cms

Minimal `tbtop` + `json-render` CMS core for Laravel.

> Greenfield package. Core CMS domains (languages, pages, redirects, blocks,
> templates, settings, forms) are implemented; not yet tagged or published to
> Packagist.

## Requirements

- PHP `^8.4`
- Laravel 11 or 12

## Installation

```bash
composer require tbtop/cms
```

Publish the config file:

```bash
php artisan vendor:publish --tag="tbtop-cms-config"
```

## Development

```bash
composer install
composer test        # Pest test suite (Orchestra Testbench)
composer lint        # Laravel Pint
composer analyse     # PHPStan / Larastan
composer serve       # Boot the Workbench host app for manual review
```

See `CLAUDE.md` for the full command reference and testing policy.

## License

MIT. See `LICENSE.md`.
