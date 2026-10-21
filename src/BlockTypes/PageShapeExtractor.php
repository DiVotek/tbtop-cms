<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\PhpDocParser as VendorPhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionMethod;
use RuntimeException;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Support\FormResolver;
use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Types\BasePageType;

/**
 * Derives each registered page type's `page.fields` the way
 * BlockContentResolver builds them: the base type's part, the subtype's part
 * over it (same key: the subtype's).
 * A part comes from the form when the type keeps the base resolveFields(),
 * else from its own `@return array{...}` — like a block's resolve().
 */
final class PageShapeExtractor
{
    private readonly Lexer $lexer;

    private readonly VendorPhpDocParser $parser;

    /** @var array<string, TypeNode> */
    private array $aliases = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, array{fields: array<string, string>, isLoose: bool, usedAliases: array<string, true>}> by type key */
    private array $parts = [];

    public function __construct()
    {
        $config = new ParserConfig(usedAttributes: []);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->parser = new VendorPhpDocParser($config, $typeParser, $constExprParser);
    }

    /** @return array{shapes: list<PageShape>, aliases: array<string, TypeNode>, warnings: list<string>} */
    public function extract(PageTypeRegistry $registry): array
    {
        $this->aliases = [];
        $this->warnings = [];
        $this->parts = [];
        $shapes = [];

        foreach ($registry->all() as $key => $type) {
            $base = $type->base();

            if ($base === $key) {
                $shapes[] = $this->shape($key, $key, null, [$type]);

                continue;
            }

            // PageTypeChecks reports the same misconfiguration; no page can carry it as (type, subtype).
            if (! $registry->has($base) || $registry->get($base)->base() !== $base) {
                $this->warnings[] = "Page type [{$key}]: base() names [{$base}], which is not a registered top-level type — no page variant emitted.";

                continue;
            }

            $shapes[] = $this->shape($key, $base, $key, [$registry->get($base), $type]);
        }

        return ['shapes' => $shapes, 'aliases' => $this->aliases, 'warnings' => $this->warnings];
    }

    /** @param  list<PageTypeInterface>  $types  base first, then the subtype */
    private function shape(string $key, string $type, ?string $subtype, array $types): PageShape
    {
        $fields = [];
        $isLoose = false;
        $usedAliases = [];

        foreach ($types as $part) {
            $extracted = $this->parts[$part->key()] ??= $this->part($part);
            $fields = [...$fields, ...$extracted['fields']];
            $isLoose = $isLoose || $extracted['isLoose'];
            $usedAliases = [...$usedAliases, ...$extracted['usedAliases']];
        }

        return new PageShape($key, $type, $subtype, $fields, $isLoose, $usedAliases);
    }

    /** @return array{fields: array<string, string>, isLoose: bool, usedAliases: array<string, true>} */
    private function part(PageTypeInterface $type): array
    {
        $key = $type->key();
        $method = new ReflectionMethod($type, 'resolveFields');
        $keepsFormResolver = $method->getDeclaringClass()->getName() === BasePageType::class;

        $shape = $keepsFormResolver ? $this->formType($type) : $this->returnTypeOf($method);

        if (! $shape instanceof ArrayShapeNode) {
            $this->warnings[] = "Page type [{$key}] has no typed @return array{...} on resolveFields() — its page.fields accept any key, typed as unknown.";

            return ['fields' => [], 'isLoose' => true, 'usedAliases' => []];
        }

        if ($keepsFormResolver) {
            foreach ((new FormResolver)->unknownFields($type->fields(new S)) as $field) {
                $this->warnings[] = "Page type [{$key}]: field [{$field['name']}] is a {$field['shape']} — its value is typed as unknown.";
            }
        } else {
            $shape = ConstFetchResolver::resolveShape($shape, $type::class, $method->getDeclaringClass());
            $this->mergeAliases((new TypeAliasCollector)->collect($type::class), $key);
        }

        $converter = new ZodConverter($this->aliases);
        $fields = [];

        try {
            foreach ($shape->items as $index => $item) {
                if ($item->keyName === null) {
                    throw new UnsupportedShapeException((string) $index, 'a positional item (page.fields is an object)');
                }

                $name = $item->keyName instanceof ConstExprStringNode ? $item->keyName->value : (string) $item->keyName;
                $fields[$name] = $converter->convert($item->valueType, $name) . ($item->optional ? '.optional()' : '');
            }
        } catch (UnsupportedShapeException $e) {
            throw new RuntimeException("Page type [{$key}]: {$e->getMessage()}", $e->getCode(), previous: $e);
        }

        return ['fields' => $fields, 'isLoose' => ! $shape->sealed, 'usedAliases' => $converter->usedAliases()];
    }

    private function formType(PageTypeInterface $type): ?TypeNode
    {
        return $this->parseReturn('/** @return ' . (new FormResolver)->phpType($type->fields(new S)) . ' */');
    }

    private function returnTypeOf(ReflectionMethod $method): ?TypeNode
    {
        $docComment = $method->getDocComment();

        return $docComment === false ? null : $this->parseReturn($docComment);
    }

    private function parseReturn(string $docComment): ?TypeNode
    {
        try {
            return $this->parser->parse(new TokenIterator($this->lexer->tokenize($docComment)))->getReturnTagValues()[0]->type ?? null;
        } catch (ParserException) {
            return null;
        }
    }

    /**
     * Same rule as for blocks: one alias name, one shape across every type in the file.
     *
     * @param  array<string, TypeNode>  $fromType
     */
    private function mergeAliases(array $fromType, string $key): void
    {
        foreach ($fromType as $name => $type) {
            if (isset($this->aliases[$name]) && (string) $this->aliases[$name] !== (string) $type) {
                throw new RuntimeException("Type alias [{$name}] is declared with two different shapes across the page types (last seen via page type [{$key}]).");
            }

            $this->aliases[$name] = $type;
        }
    }
}
