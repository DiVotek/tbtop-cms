<?php

declare(strict_types=1);

use Tbtop\Cms\Frontend\Seo\JsonLd;

it('builds an Organization object with logo and sameAs', function (): void {
    $jsonLd = new JsonLd;

    $object = $jsonLd->identity('organization', 'Acme Inc', 'https://example.com/', 'https://example.com/logo.png', [
        'https://twitter.com/acme', 'https://github.com/acme',
    ]);

    expect($object)->toBe([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'Acme Inc',
        'url' => 'https://example.com/',
        'logo' => 'https://example.com/logo.png',
        'sameAs' => ['https://twitter.com/acme', 'https://github.com/acme'],
    ]);
});

it('builds a Person object without a logo key even when one is passed', function (): void {
    $jsonLd = new JsonLd;

    $object = $jsonLd->identity('person', 'Jane Doe', 'https://example.com/', 'https://example.com/logo.png', []);

    expect($object)->toBe([
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        'name' => 'Jane Doe',
        'url' => 'https://example.com/',
    ])->and($object)->not->toHaveKey('logo')
        ->and($object)->not->toHaveKey('sameAs');
});

it('returns null when the identity type is none', function (): void {
    $jsonLd = new JsonLd;

    expect($jsonLd->identity('none', 'Acme Inc', 'https://example.com/', null, []))->toBeNull();
});

it('returns null when the resolved name is empty regardless of type', function (): void {
    $jsonLd = new JsonLd;

    expect($jsonLd->identity('organization', '', 'https://example.com/', null, []))->toBeNull();
});

it('omits sameAs when the list is empty', function (): void {
    $jsonLd = new JsonLd;

    $object = $jsonLd->identity('organization', 'Acme Inc', 'https://example.com/', null, []);

    expect($object)->not->toBeNull()
        ->and($object)->not->toHaveKey('sameAs')
        ->and($object)->not->toHaveKey('logo');
});
