<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasImportTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TypeAliasTagValueNode;
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
        $this->collectFromDocComment($class->getDocComment() ?: null, $class->name);
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
            $this->collectFromDocComment($trait->getDocComment() ?: null, $selfClass);
            $this->collectFromTraits($trait, $selfClass);
        }
    }

    private function collectFromDocComment(?string $docComment, string $selfClass): void
    {
        if ($docComment === null) {
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
            $this->importAlias($import);
        }
    }

    private function addAlias(TypeAliasTagValueNode $tag, string $selfClass): void
    {
        $this->mergeAlias($tag->alias, SelfTypeResolver::resolve($tag->type, $selfClass));
    }

    private function importAlias(TypeAliasImportTagValueNode $import): void
    {
        $from = $import->importedFrom->name;

        if (! class_exists($from) && ! trait_exists($from) && ! interface_exists($from)) {
            throw new RuntimeException("@phpstan-import-type [{$import->importedAlias}] names an unknown class [{$from}].");
        }

        $imported = (new self)->collect($from);

        if (! isset($imported[$import->importedAlias])) {
            throw new RuntimeException("@phpstan-import-type [{$import->importedAlias}] is not declared on [{$from}].");
        }

        $localName = $import->importedAs ?? $import->importedAlias;
        $this->mergeAlias($localName, $imported[$import->importedAlias]);

        // The imported alias's own body may reference sibling aliases from
        // the same source class — pull in every alias reachable from $from,
        // not just the one named, so the converter can resolve those too.
        foreach ($imported as $name => $type) {
            $this->mergeAlias($name, $type);
        }
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
