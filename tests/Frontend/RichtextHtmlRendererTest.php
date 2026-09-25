<?php

declare(strict_types=1);

use Tbtop\Cms\Richtext\RichtextHtmlRenderer;

beforeEach(function (): void {
    $this->renderer = new RichtextHtmlRenderer;
});

function textNode(string $text, int $format = 0): array
{
    return ['type' => 'text', 'text' => $text, 'format' => $format];
}

function editorState(array $children): array
{
    return ['root' => ['children' => $children]];
}

it('renders a paragraph', function (): void {
    $html = $this->renderer->render(editorState([
        ['type' => 'paragraph', 'children' => [textNode('hello')]],
    ]));

    expect($html)->toBe('<p>hello</p>');
});

it('renders headings and clamps invalid tags to a paragraph', function (): void {
    $valid = $this->renderer->render(editorState([
        ['type' => 'heading', 'tag' => 'h2', 'children' => [textNode('Title')]],
    ]));

    $invalid = $this->renderer->render(editorState([
        ['type' => 'heading', 'tag' => 'h7', 'children' => [textNode('Title')]],
    ]));

    expect($valid)->toBe('<h2>Title</h2>')
        ->and($invalid)->toBe('<p>Title</p>');
});

it('renders a quote', function (): void {
    $html = $this->renderer->render(editorState([
        ['type' => 'quote', 'children' => [textNode('wisdom')]],
    ]));

    expect($html)->toBe('<blockquote>wisdom</blockquote>');
});

it('renders an unordered and ordered list with start only when greater than 1', function (): void {
    $bullet = $this->renderer->render(editorState([
        [
            'type' => 'list',
            'listType' => 'bullet',
            'tag' => 'ul',
            'children' => [
                ['type' => 'listitem', 'children' => [textNode('one')]],
            ],
        ],
    ]));

    $orderedDefault = $this->renderer->render(editorState([
        [
            'type' => 'list',
            'listType' => 'number',
            'tag' => 'ol',
            'start' => 1,
            'children' => [
                ['type' => 'listitem', 'children' => [textNode('one')]],
            ],
        ],
    ]));

    $orderedStart = $this->renderer->render(editorState([
        [
            'type' => 'list',
            'listType' => 'number',
            'tag' => 'ol',
            'start' => 3,
            'children' => [
                ['type' => 'listitem', 'children' => [textNode('three')]],
            ],
        ],
    ]));

    expect($bullet)->toBe('<ul><li>one</li></ul>')
        ->and($orderedDefault)->toBe('<ol><li>one</li></ol>')
        ->and($orderedStart)->toBe('<ol start="3"><li>three</li></ol>');
});

it('renders a nested list inside its parent listitem', function (): void {
    $html = $this->renderer->render(editorState([
        [
            'type' => 'list',
            'listType' => 'bullet',
            'tag' => 'ul',
            'children' => [
                [
                    'type' => 'listitem',
                    'children' => [
                        textNode('parent'),
                        [
                            'type' => 'list',
                            'listType' => 'bullet',
                            'tag' => 'ul',
                            'children' => [
                                ['type' => 'listitem', 'children' => [textNode('child')]],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]));

    expect($html)->toBe('<ul><li>parent<ul><li>child</li></ul></li></ul>');
});

it('bounds deeply nested and cyclic documents', function (): void {
    $nested = textNode('too deep');

    for ($depth = 0; $depth < 65; $depth++) {
        $nested = ['type' => 'unknown', 'children' => [$nested]];
    }

    $cyclic = ['type' => 'unknown'];
    $cyclic['children'] = [&$cyclic];

    expect($this->renderer->render(editorState([$nested])))->toBe('')
        ->and($this->renderer->render(editorState([$cyclic])))->toBe('');
});

it('bounds the total number of rendered nodes', function (): void {
    $children = array_fill(0, 10001, textNode('x'));

    expect($this->renderer->render(editorState($children)))->toBe(str_repeat('x', 10000));
});

it('renders code blocks and linebreaks', function (): void {
    $code = $this->renderer->render(editorState([
        ['type' => 'code', 'children' => [textNode('const x = 1;')]],
    ]));

    $linebreak = $this->renderer->render(editorState([
        ['type' => 'paragraph', 'children' => [textNode('a'), ['type' => 'linebreak'], textNode('b')]],
    ]));

    expect($code)->toBe('<pre><code>const x = 1;</code></pre>')
        ->and($linebreak)->toBe('<p>a<br>b</p>');
});

it('wraps every text format bit and ignores the highlight bit', function (): void {
    $bold = $this->renderer->render(editorState([['type' => 'paragraph', 'children' => [textNode('x', 1)]]]));
    $italic = $this->renderer->render(editorState([['type' => 'paragraph', 'children' => [textNode('x', 2)]]]));
    $strike = $this->renderer->render(editorState([['type' => 'paragraph', 'children' => [textNode('x', 4)]]]));
    $underline = $this->renderer->render(editorState([['type' => 'paragraph', 'children' => [textNode('x', 8)]]]));
    $code = $this->renderer->render(editorState([['type' => 'paragraph', 'children' => [textNode('x', 16)]]]));
    $sub = $this->renderer->render(editorState([['type' => 'paragraph', 'children' => [textNode('x', 32)]]]));
    $sup = $this->renderer->render(editorState([['type' => 'paragraph', 'children' => [textNode('x', 64)]]]));
    $highlight = $this->renderer->render(editorState([['type' => 'paragraph', 'children' => [textNode('x', 128)]]]));
    $combo = $this->renderer->render(editorState([['type' => 'paragraph', 'children' => [textNode('x', 1 | 2)]]]));

    expect($bold)->toBe('<p><strong>x</strong></p>')
        ->and($italic)->toBe('<p><em>x</em></p>')
        ->and($strike)->toBe('<p><s>x</s></p>')
        ->and($underline)->toBe('<p><u>x</u></p>')
        ->and($code)->toBe('<p><code>x</code></p>')
        ->and($sub)->toBe('<p><sub>x</sub></p>')
        ->and($sup)->toBe('<p><sup>x</sup></p>')
        ->and($highlight)->toBe('<p>x</p>')
        ->and($combo)->toBe('<p><em><strong>x</strong></em></p>');
});

it('renders alignment as a style attribute from a fixed allowlist', function (): void {
    $left = $this->renderer->render(editorState([['type' => 'paragraph', 'format' => 'left', 'children' => [textNode('x')]]]));
    $empty = $this->renderer->render(editorState([['type' => 'paragraph', 'format' => '', 'children' => [textNode('x')]]]));
    $center = $this->renderer->render(editorState([['type' => 'paragraph', 'format' => 'center', 'children' => [textNode('x')]]]));
    $right = $this->renderer->render(editorState([['type' => 'paragraph', 'format' => 'right', 'children' => [textNode('x')]]]));
    $justify = $this->renderer->render(editorState([['type' => 'paragraph', 'format' => 'justify', 'children' => [textNode('x')]]]));

    expect($left)->toBe('<p>x</p>')
        ->and($empty)->toBe('<p>x</p>')
        ->and($center)->toBe('<p style="text-align:center">x</p>')
        ->and($right)->toBe('<p style="text-align:right">x</p>')
        ->and($justify)->toBe('<p style="text-align:justify">x</p>');
});

it('renders links with allowed schemes and passes through target/title', function (): void {
    $http = $this->renderer->render(editorState([
        ['type' => 'link', 'url' => 'https://example.com', 'children' => [textNode('go')]],
    ]));

    $relative = $this->renderer->render(editorState([
        ['type' => 'link', 'url' => '/about', 'children' => [textNode('about')]],
    ]));

    $anchor = $this->renderer->render(editorState([
        ['type' => 'link', 'url' => '#section', 'children' => [textNode('jump')]],
    ]));

    $mailto = $this->renderer->render(editorState([
        ['type' => 'autolink', 'url' => 'mailto:a@b.com', 'children' => [textNode('mail')]],
    ]));

    expect($http)->toBe('<a href="https://example.com">go</a>')
        ->and($relative)->toBe('<a href="/about">about</a>')
        ->and($anchor)->toBe('<a href="#section">jump</a>')
        ->and($mailto)->toBe('<a href="mailto:a@b.com">mail</a>');
});

it('forces rel=noopener noreferrer when target is _blank and ignores stored rel', function (): void {
    $html = $this->renderer->render(editorState([
        [
            'type' => 'link',
            'url' => 'https://example.com',
            'target' => '_blank',
            'rel' => 'nofollow',
            'title' => 'Example',
            'children' => [textNode('go')],
        ],
    ]));

    expect($html)->toBe('<a href="https://example.com" title="Example" target="_blank" rel="noopener noreferrer">go</a>');
});

it('drops javascript: urls but keeps the link text', function (): void {
    $html = $this->renderer->render(editorState([
        ['type' => 'link', 'url' => 'javascript:alert(1)', 'children' => [textNode('click me')]],
    ]));

    expect($html)->toBe('click me');
});

it('drops data: urls but keeps the link text', function (): void {
    $html = $this->renderer->render(editorState([
        ['type' => 'link', 'url' => 'data:text/html,<script>alert(1)</script>', 'children' => [textNode('bad')]],
    ]));

    expect($html)->toBe('bad');
});

it('drops javascript: urls regardless of scheme casing', function (): void {
    $html = $this->renderer->render(editorState([
        ['type' => 'link', 'url' => 'JavaScript:alert(1)', 'children' => [textNode('click me')]],
    ]));

    expect($html)->toBe('click me');
});

it('drops javascript: urls disguised with whitespace the browser strips before parsing', function (string $url): void {
    $html = $this->renderer->render(editorState([
        ['type' => 'link', 'url' => $url, 'children' => [textNode('click me')]],
    ]));

    expect($html)->toBe('click me');
})->with([
    'leading space' => [' javascript:alert(1)'],
    'leading tab' => ["\tjavascript:alert(1)"],
    'leading newline' => ["\njavascript:alert(1)"],
    'tab inside the scheme' => ["java\tscript:alert(1)"],
    'newline inside the scheme' => ["java\nscript:alert(1)"],
]);

it('escapes script tags inside text nodes', function (): void {
    $html = $this->renderer->render(editorState([
        ['type' => 'paragraph', 'children' => [textNode('<script>alert(1)</script>')]],
    ]));

    expect($html)->toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
});

it('escapes an onmouseover attempt delivered via the title attribute', function (): void {
    $html = $this->renderer->render(editorState([
        [
            'type' => 'link',
            'url' => 'https://example.com',
            'title' => '" onmouseover="alert(1)',
            'children' => [textNode('go')],
        ],
    ]));

    expect($html)->toBe('<a href="https://example.com" title="&quot; onmouseover=&quot;alert(1)">go</a>');
});

it('wraps a legacy bare string as one paragraph', function (): void {
    expect($this->renderer->render('hello world'))->toBe('<p>hello world</p>');
});

it('accepts a json string of the same serialized editor state', function (): void {
    $json = json_encode(editorState([['type' => 'paragraph', 'children' => [textNode('from json')]]]));

    expect($this->renderer->render($json))->toBe('<p>from json</p>');
});

it('returns an empty string for garbage input', function (): void {
    expect($this->renderer->render(null))->toBe('')
        ->and($this->renderer->render(''))->toBe('')
        ->and($this->renderer->render(42))->toBe('')
        ->and($this->renderer->render(['nonsense' => true]))->toBe('');
});

it('wraps non-json bare strings as a legacy paragraph', function (): void {
    expect($this->renderer->render('not json {'))->toBe('<p>not json {</p>');
});

it('renders a legacy html string as markup, the way the editor reads it', function (): void {
    expect($this->renderer->render('<p>Hello <strong>world</strong></p>'))
        ->toBe('<p>Hello <strong>world</strong></p>');
});

it('sanitises a legacy html string by rebuilding it through the node renderer', function (): void {
    $html = $this->renderer->render(
        '<p onclick="alert(1)" class="x">Hi<script>alert(2)</script><style>p{}</style>'
        . ' <a href="javascript:alert(3)" onmouseover="alert(4)">bad</a></p>',
    );

    expect($html)->toBe('<p>Hi bad</p>');
});
