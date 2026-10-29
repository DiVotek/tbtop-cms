<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Tbtop\Cms\Frontend\Markdown\MarkdownRequest;

function acceptRequest(string $accept, array $headers = []): Request
{
    return Request::create('/about', 'GET', server: ['HTTP_ACCEPT' => $accept, ...$headers]);
}

it('reads the Accept header the way agents and browsers send it', function (string $accept, bool $markdown): void {
    expect(MarkdownRequest::accepts(acceptRequest($accept)))->toBe($markdown);
})->with([
    'markdown only' => ['text/markdown', true],
    'markdown listed first' => ['text/markdown, text/html', true],
    'markdown ranked higher' => ['text/html;q=0.8, text/markdown', true],
    'html listed first' => ['text/html, text/markdown', false],
    'html ranked higher' => ['text/markdown;q=0.5, text/html', false],
    'browser' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', false],
    'wildcard only' => ['*/*', false],
    'markdown refused' => ['text/markdown;q=0', false],
]);

it('never answers an Inertia visit with markdown', function (): void {
    expect(MarkdownRequest::accepts(acceptRequest('text/markdown', ['HTTP_X_INERTIA' => 'true'])))->toBeFalse();
});

it('maps a .md path to the page path', function (string $path, ?string $page): void {
    expect(MarkdownRequest::suffixPath($path))->toBe($page);
})->with([
    ['about.md', 'about'],
    ['blog/post.md', 'blog/post'],
    ['index.md', ''],
    ['about', null],
    ['.md', null],
]);
