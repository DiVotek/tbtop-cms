<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tbtop\Cms\Admin\Pages\Support\RedirectForm;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Redirect\Models\Redirect;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

it('creates a manual global redirect', function (): void {
    $redirect = RedirectForm::save(['from_path' => 'old', 'to_path' => 'new', 'locale' => '', 'status_code' => '301']);

    expect($redirect->from_path)->toBe('old')
        ->and($redirect->to_path)->toBe('new')
        ->and($redirect->locale)->toBeNull()
        ->and($redirect->status_code)->toBe(301)
        ->and($redirect->is_auto)->toBeFalse();
});

it('rejects a self redirect', function (): void {
    RedirectForm::save(['from_path' => 'loop', 'to_path' => 'loop', 'locale' => '', 'status_code' => '301']);
})->throws(ValidationException::class);

it('rejects a redirect that shadows a live page', function (): void {
    Page::factory()->create(['name' => ['en' => 'About']]);

    RedirectForm::save(['from_path' => 'about', 'to_path' => 'somewhere', 'locale' => '', 'status_code' => '301']);
})->throws(ValidationException::class);

it('rejects a redirect that closes a loop', function (): void {
    Redirect::create(['locale' => 'en', 'from_path' => 'b', 'to_path' => 'a', 'status_code' => 301]);

    RedirectForm::save(['from_path' => 'a', 'to_path' => 'b', 'locale' => 'en', 'status_code' => '301']);
})->throws(ValidationException::class);

it('rejects an unsupported status code', function (): void {
    RedirectForm::save(['from_path' => 'x', 'to_path' => 'y', 'locale' => '', 'status_code' => '307']);
})->throws(ValidationException::class);

it('updates a redirect and marks it manual', function (): void {
    $auto = Redirect::create(['locale' => 'en', 'from_path' => 'p', 'to_path' => 'q', 'status_code' => 301, 'is_auto' => true]);

    RedirectForm::save(['from_path' => 'p', 'to_path' => 'q2', 'locale' => 'en', 'status_code' => '302'], $auto->id);
    $auto->refresh();

    expect($auto->to_path)->toBe('q2')
        ->and($auto->status_code)->toBe(302)
        ->and($auto->is_auto)->toBeFalse();
});

it('loads a redirect row for editing', function (): void {
    $redirect = Redirect::create(['locale' => 'en', 'from_path' => 'm', 'to_path' => 'n', 'status_code' => 302]);

    $row = RedirectForm::loadRow($redirect->id);

    expect($row['from_path'])->toBe('m')
        ->and($row['to_path'])->toBe('n')
        ->and($row['locale'])->toBe('en')
        ->and($row['status_code'])->toBe('302');
});

it('rejects an external URL as the from path', function (): void {
    RedirectForm::save(['from_path' => 'https://evil.test/x', 'to_path' => 'safe', 'locale' => '', 'status_code' => '301']);
})->throws(ValidationException::class);

it('rejects an external to path whose host is not allowlisted', function (): void {
    config()->set('tbtop-cms.redirects.allowed_external_hosts', []);

    $caught = null;

    try {
        RedirectForm::save(['from_path' => 'gone', 'to_path' => 'https://evil.test/landing', 'locale' => '', 'status_code' => '301']);
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught?->errors()['to_path'][0])->toContain('not allowlisted')
        ->toContain('tbtop-cms.redirects.allowed_external_hosts');
});

it('rejects an external to path whose host is not allowlisted, localized to uk', function (): void {
    app()->setLocale('uk');
    config()->set('tbtop-cms.redirects.allowed_external_hosts', []);

    $caught = null;

    try {
        RedirectForm::save(['from_path' => 'gone', 'to_path' => 'https://evil.test/landing', 'locale' => '', 'status_code' => '301']);
    } catch (ValidationException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught?->errors()['to_path'][0])->toBe(__('tbtop-cms::redirects.validation.to_host_not_allowlisted'))
        ->toContain('tbtop-cms.redirects.allowed_external_hosts');
});

it('accepts an external to path whose host is allowlisted', function (): void {
    config()->set('tbtop-cms.redirects.allowed_external_hosts', ['example.com']);

    $redirect = RedirectForm::save(['from_path' => 'gone', 'to_path' => 'https://example.com/landing', 'locale' => '', 'status_code' => '301']);

    expect($redirect->to_path)->toBe('https://example.com/landing');
});

it('matches the allowlisted host case-insensitively', function (): void {
    config()->set('tbtop-cms.redirects.allowed_external_hosts', ['Example.COM']);

    $redirect = RedirectForm::save(['from_path' => 'gone', 'to_path' => 'https://EXAMPLE.com/landing', 'locale' => '', 'status_code' => '301']);

    expect($redirect->to_path)->toBe('https://EXAMPLE.com/landing');
});

it('rejects a subdomain of an allowlisted host', function (): void {
    config()->set('tbtop-cms.redirects.allowed_external_hosts', ['example.com']);

    RedirectForm::save(['from_path' => 'gone', 'to_path' => 'https://sub.example.com/landing', 'locale' => '', 'status_code' => '301']);
})->throws(ValidationException::class);

it('rejects a protocol-relative to path even when the host is allowlisted', function (): void {
    config()->set('tbtop-cms.redirects.allowed_external_hosts', ['evil.com']);

    RedirectForm::save(['from_path' => 'gone', 'to_path' => '//evil.com', 'locale' => '', 'status_code' => '301']);
})->throws(ValidationException::class);

it('resolves the userinfo-tricked host to the real host, not the disguised one', function (): void {
    // "https://evil.com\@good.com" looks like it points at evil.com, but parse_url
    // reads "evil.com\" as userinfo and "good.com" as the actual host.
    config()->set('tbtop-cms.redirects.allowed_external_hosts', ['good.com']);

    $redirect = RedirectForm::save(['from_path' => 'gone', 'to_path' => 'https://evil.com\@good.com', 'locale' => '', 'status_code' => '301']);

    expect($redirect->to_path)->toBe('https://evil.com\@good.com');
});

it('rejects the userinfo-tricked host when the real host is not allowlisted', function (): void {
    config()->set('tbtop-cms.redirects.allowed_external_hosts', ['evil.com']);

    RedirectForm::save(['from_path' => 'gone', 'to_path' => 'https://evil.com\@good.com', 'locale' => '', 'status_code' => '301']);
})->throws(ValidationException::class);

it('rejects an external to path that parse_url cannot parse', function (): void {
    config()->set('tbtop-cms.redirects.allowed_external_hosts', ['example.com']);

    RedirectForm::save(['from_path' => 'gone', 'to_path' => 'https:///example.com', 'locale' => '', 'status_code' => '301']);
})->throws(ValidationException::class);
