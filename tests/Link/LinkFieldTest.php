<?php

declare(strict_types=1);

use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Fields\Link;
use Tbtop\Cms\Admin\Pages\Support\PrefixedField;
use Tbtop\Cms\Link\LinkType;

it('registers the link kind on the S builder', function (): void {
    expect((new S)->link('cta'))->toBeInstanceOf(Link::class);
});

it('serializes as a leaf link node with no translatable flag', function (): void {
    $node = (new S)->link('cta')->label('Call to action')->toNode();

    expect($node->kind)->toBe('link')
        ->and($node->name)->toBe('cta')
        ->and($node->options['label'])->toBe('Call to action')
        ->and($node->options)->not->toHaveKey('translatable')
        ->and($node->options)->not->toHaveKey('fields');
});

it('carries the page-search endpoint in its options', function (): void {
    expect((new S)->link('cta')->toNode()->options['searchEndpoint'])->toBe('/admin/link/page-search');
});

it('renders the endpoint against a host custom admin prefix', function (): void {
    config(['tbtop-cms.admin.prefix' => 'panel']);

    expect((new S)->link('cta')->toNode()->options['searchEndpoint'])->toBe('/panel/link/page-search');
});

it('refuses to be made translatable', function (): void {
    (new S)->link('cta')->translatable();
})->throws(InvalidArgumentException::class, 'not translatable');

it('is not a translatable field', function (): void {
    expect((new S)->link('cta')->isTranslatableField())->toBeFalse();
});

it('validates every type by default', function (): void {
    expect((new S)->link('cta')->ruleEntries())->toBe(['nullable', 'array', 'link_shape']);
});

it('narrows the shape rule to the declared types', function (): void {
    $rules = (new S)->link('cta')->types([LinkType::Page, LinkType::External])->ruleEntries();

    expect($rules)->toBe(['nullable', 'array', 'link_shape:page,external']);
});

it('accepts plain strings in types()', function (): void {
    expect((new S)->link('cta')->types(['email'])->ruleEntries())->toContain('link_shape:email');
});

it('exposes the declared types to the client', function (): void {
    expect((new S)->link('cta')->types(['page', 'email'])->toNode()->options['types'])->toBe(['page', 'email']);
});

it('reindexes a keyed types() array so it serializes as a JSON list', function (): void {
    $node = (new S)->link('cta')->types([2 => 'page', 5 => 'email'])->toNode();

    expect($node->options['types'])->toBe(['page', 'email'])
        ->and(json_encode($node->options['types']))->toBe('["page","email"]');
});

it('rejects an unknown type', function (): void {
    (new S)->link('cta')->types(['ftp']);
})->throws(InvalidArgumentException::class, 'unknown link type(s) ftp');

it('drops nullable and keeps the shape rule once required', function (): void {
    $rules = (new S)->link('cta')->required()->ruleEntries();

    expect($rules)->toBe(['required', 'array', 'link_shape']);
});

it('does not care whether types() comes before or after required()', function (): void {
    $before = (new S)->link('cta')->types(['email'])->required()->ruleEntries();
    $after = (new S)->link('cta')->required()->types(['email'])->ruleEntries();

    expect($before)->toBe($after)
        ->and($before)->toBe(['required', 'array', 'link_shape:email']);
});

it('survives the block editor rename without doubling its rules', function (): void {
    $renamed = PrefixedField::rename((new S)->link('cta')->types(['page']), 'block_x_cta');

    expect($renamed->name)->toBe('block_x_cta')
        ->and($renamed->ruleEntries())->toBe(['nullable', 'array', 'link_shape:page'])
        ->and($renamed->toNode()->options['types'])->toBe(['page']);
});

it('keeps a required link required after the rename, gated on the block being active', function (): void {
    $renamed = PrefixedField::rename((new S)->link('cta')->required(), 'block_x_cta', 'block_x_active');

    expect($renamed->ruleEntries())->toBe(['required_unless:block_x_active,false,0', 'array', 'link_shape']);
});
