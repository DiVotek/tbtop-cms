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
