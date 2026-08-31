<?php

declare(strict_types=1);

use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Types\PageType;
use Tbtop\Cms\Tests\Fixtures\EmptyKeyPageType;
use Tbtop\Cms\Tests\Fixtures\FaqPageType;

it('registers the built-in page types', function (): void {
    $registry = new PageTypeRegistry;

    expect($registry->has('page'))->toBeTrue()
        ->and($registry->has('category'))->toBeTrue()
        ->and($registry->has('article'))->toBeTrue()
        ->and($registry->has('widget'))->toBeFalse();
});

it('exposes type hierarchy metadata', function (): void {
    $registry = new PageTypeRegistry;

    expect($registry->get('category')->allowedChildren())->toBe(['category', 'article'])
        ->and($registry->get('category')->maxDepth())->toBe(3)
        ->and($registry->get('article')->requiresParent())->toBeTrue()
        ->and($registry->get('page')->allowedChildren())->toBe([]);
});

it('registers a project type from config', function (): void {
    config()->set('tbtop-cms.pages.types', [FaqPageType::class]);

    expect((new PageTypeRegistry)->has('faq'))->toBeTrue();
});

it('rejects a class that is not a page type', function (): void {
    config()->set('tbtop-cms.pages.types', [stdClass::class]);

    expect(fn () => (new PageTypeRegistry)->keys())->toThrow(InvalidArgumentException::class);
});

it('rejects a duplicate type key', function (): void {
    config()->set('tbtop-cms.pages.types', [PageType::class]);

    expect(fn () => (new PageTypeRegistry)->keys())->toThrow(InvalidArgumentException::class);
});

it('rejects an empty type key', function (): void {
    config()->set('tbtop-cms.pages.types', [EmptyKeyPageType::class]);

    expect(fn () => (new PageTypeRegistry)->keys())->toThrow(InvalidArgumentException::class);
});

it('returns a page type registered by an extension', function (): void {
    PageTypeRegistry::extend(fn (): array => [FaqPageType::class]);

    $registry = new PageTypeRegistry;

    expect($registry->has('faq'))->toBeTrue()
        ->and($registry->get('faq'))->toBeInstanceOf(FaqPageType::class)
        ->and($registry->keys())->toContain('faq')
        ->and(array_keys($registry->all()))->toContain('faq');
});

it('registers two extensions in order after the built-ins', function (): void {
    PageTypeRegistry::extend(fn (): array => [FaqPageType::class]);

    expect((new PageTypeRegistry)->keys())->toBe(['page', 'category', 'article', 'faq']);
});

it('rejects a duplicate type key coming from an extension', function (): void {
    PageTypeRegistry::extend(fn (): array => [PageType::class]);

    expect(fn () => (new PageTypeRegistry)->keys())->toThrow(InvalidArgumentException::class);
});

it('does not invoke the extension callable until the registry is first used', function (): void {
    $calls = 0;
    PageTypeRegistry::extend(function () use (&$calls): array {
        $calls++;

        return [FaqPageType::class];
    });

    $registry = new PageTypeRegistry;

    expect($calls)->toBe(0);

    $registry->keys();

    expect($calls)->toBe(1);
});

it('does not see an extension registered before flushExtensions()', function (): void {
    PageTypeRegistry::extend(fn (): array => [FaqPageType::class]);
    PageTypeRegistry::flushExtensions();

    expect((new PageTypeRegistry)->has('faq'))->toBeFalse();
});
