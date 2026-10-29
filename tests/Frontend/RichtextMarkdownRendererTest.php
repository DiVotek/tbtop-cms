<?php

declare(strict_types=1);

use Tbtop\Cms\Richtext\RichtextMarkdownRenderer;

function richtextMarkdown(mixed $value): string
{
    return (new RichtextMarkdownRenderer)->render($value);
}

it('renders paragraphs, headings from H2 and inline formats', function (): void {
    expect(richtextMarkdown('<h1>Title</h1><h3>Sub</h3><p><strong>Bold</strong>, <em>it</em>, <s>gone</s> and <code>a`b</code></p>'))
        ->toBe("## Title\n\n### Sub\n\n**Bold**, _it_, ~~gone~~ and ``a`b``");
});

it('renders ordered and nested lists', function (): void {
    expect(richtextMarkdown('<ol><li>One<ul><li>Inner</li></ul></li><li>Two</li></ol>'))
        ->toBe("1. One\n   - Inner\n2. Two");
});

it('keeps allowed links absolute and drops the rest to their text', function (): void {
    expect(richtextMarkdown('<p><a href="/prices">prices</a> <a href="mailto:a@b.c">mail</a> <a href="javascript:alert(1)">bad</a></p>'))
        ->toBe('[prices](' . url('/prices') . ') [mail](mailto:a@b.c) bad');
});

it('escapes characters that would turn text into markup', function (): void {
    expect(richtextMarkdown('<p># not a heading *x* [y]</p><p>1. not a list</p>'))
        ->toBe("\\# not a heading \\*x\\* \\[y\\]\n\n1\\. not a list");
});

it('fences code past the backticks inside it', function (): void {
    expect(richtextMarkdown('<pre>a ``` b</pre>'))->toBe("````\na ``` b\n````");
});

it('reads a Lexical state and plain text as well as HTML', function (): void {
    $state = ['root' => ['children' => [['type' => 'quote', 'children' => [['type' => 'text', 'text' => 'Said', 'format' => 0]]]]]];

    expect(richtextMarkdown($state))->toBe('> Said')
        ->and(richtextMarkdown('Plain text'))->toBe('Plain text')
        ->and(richtextMarkdown(null))->toBe('');
});
