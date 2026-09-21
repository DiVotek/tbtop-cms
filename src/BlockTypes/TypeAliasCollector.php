<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasImportTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser as VendorPhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionClass;
use RuntimeException;

/**
 * Resolves `@phpstan-type`/`@psalm-type` and `@phpstan-import-type` across a
 * block class, its parents, and its traits — the same lookup PHPStan itself
 * does for local type aliases, reimplemented here because we need the raw
 * TypeNode, not PHPStan's own type-checking result.
 */
final class TypeAliasCollector
{
    private readonly Lexer $lexer;

    private readonly VendorPhpDocParser $parser;

    /** @var array<string, TypeNode> */
    private array $aliases = [];

    public function __construct()
    {
        $config = new ParserConfig(usedAttributes: []);
        $this->lexer = new Lexer($config);
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);
        $this->parser = new VendorPhpDocParser($config, $typeParser, $constExprParser);
    }

    /**
     * @param  class-string  $class
     * @return array<string, TypeNode> alias name => its type, aliases-of-aliases left unresolved
     */
    public function collect(string $class): array
    {
        $this->aliases = [];
        $this->collectFromClassAndTraits(new ReflectionClass($class));

        return $this->aliases;
    }

    /** @param  ReflectionClass<object>  $class */
    private function collectFromClassAndTraits(ReflectionClass $class): void
    {
        $this->collectFromDocComment($class, $class->name);
        $this->collectFromTraits($class, $class->name);

        $parent = $class->getParentClass();
        if ($parent !== false) {
            $this->collectFromClassAndTraits($parent);
        }
    }

    /**
     * Traits use traits; every level may declare aliases. `self::` inside them
     * still means the using class.
     *
     * @param  ReflectionClass<object>  $owner
     */
    private function collectFromTraits(ReflectionClass $owner, string $selfClass): void
    {
        foreach ($owner->getTraits() as $trait) {
            $this->collectFromDocComment($trait, $selfClass);
            $this->collectFromTraits($trait, $selfClass);
        }
    }

    /** @param  ReflectionClass<object>  $declaring  the class or trait carrying the docblock */
    private function collectFromDocComment(ReflectionClass $declaring, string $selfClass): void
    {
        $docComment = $declaring->getDocComment();

        if ($docComment === false) {
            return;
        }

        $doc = $this->parseDocComment($docComment);

        foreach ($doc->getTypeAliasTagValues('@phpstan-type') as $tag) {
            $this->addAlias($tag, $selfClass);
        }

        foreach ($doc->getTypeAliasTagValues('@psalm-type') as $tag) {
            $this->addAlias($tag, $selfClass);
        }

        foreach ($doc->getTypeAliasImportTagValues() as $import) {
            $this->importAlias($import, $declaring);
        }
    }

    private function addAlias(TypeAliasTagValueNode $tag, string $selfClass): void
    {
        $this->mergeAlias($tag->alias, SelfTypeResolver::resolve($tag->type, $selfClass));
    }

    /** @param  ReflectionClass<object>  $declaring */
    private function importAlias(TypeAliasImportTagValueNode $import, ReflectionClass $declaring): void
    {
        $from = DocblockClassResolver::resolve($import->importedFrom->name, $declaring);

        if (! class_exists($from) && ! trait_exists($from) && ! interface_exists($from)) {
            throw new RuntimeException("@phpstan-import-type [{$import->importedAlias}] names an unknown class [{$from}].");
        }

        /** @var class-string $from */
        $imported = (new self)->collect($from);

        if (! isset($imported[$import->importedAlias])) {
            throw new RuntimeException("@phpstan-import-type [{$import->importedAlias}] is not declared on [{$from}].");
        }

        $localName = $import->importedAs ?? $import->importedAlias;
        $this->mergeAlias($localName, $imported[$import->importedAlias]);

        // The imported body may refer to sibling aliases of the source class;
        // those come along under their own names. Unrelated siblings do not,
        // so `Item from A as AItem` + `Item from B as BItem` can coexist.
        foreach (self::referencedAliases($imported[$import->importedAlias], $imported) as $name) {
            $this->mergeAlias($name, $imported[$name]);
        }
    }

    /**
     * @param  array<string, TypeNode>  $pool
     * @return list<string> alias names reachable from $type, transitively
     */
    private static function referencedAliases(TypeNode $type, array $pool): array
    {
        $found = [];
        $queue = [$type];

        while ($queue !== []) {
            $visitor = new class extends AbstractNodeVisitor
            {
                /** @var list<string> */
                public array $names = [];

                public function enterNode(Node $node): null
                {
                    if ($node instanceof IdentifierTypeNode) {
                        $this->names[] = $node->name;
                    }

                    return null;
                }
            };
            (new NodeTraverser([$visitor]))->traverse([array_shift($queue)]);

            foreach ($visitor->names as $name) {
                if (isset($pool[$name]) && ! isset($found[$name])) {
                    $found[$name] = true;
                    $queue[] = $pool[$name];
                }
            }
        }

        return array_keys($found);
    }

    private function mergeAlias(string $name, TypeNode $type): void
    {
        if (isset($this->aliases[$name]) && (string) $this->aliases[$name] !== (string) $type) {
            throw new RuntimeException("Type alias [{$name}] is declared twice with different shapes.");
        }

        $this->aliases[$name] = $type;
    }

    private function parseDocComment(string $docComment): PhpDocNode
    {
        $tokens = new TokenIterator($this->lexer->tokenize($docComment));

        return $this->parser->parse($tokens);
    }
}
