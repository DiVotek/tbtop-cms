<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use ReflectionClass;

/**
 * Resolves a class name written in a docblock the way PHP would resolve it
 * in that file: leading `\` is absolute, the first segment may be a `use`
 * alias, anything else lives in the declaring file's namespace.
 */
final class DocblockClassResolver
{
    /** @var array<string, array<string, string>> file => alias => FQCN */
    private static array $importsByFile = [];

    /** @param  ReflectionClass<object>  $declaring */
    public static function resolve(string $name, ReflectionClass $declaring): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);
        $imports = self::imports($declaring);
        $first = strtolower($segments[0]);

        if (isset($imports[$first])) {
            $segments[0] = $imports[$first];

            return implode('\\', $segments);
        }

        $namespace = $declaring->getNamespaceName();

        return $namespace === '' ? $name : "{$namespace}\\{$name}";
    }

    /**
     * @param  ReflectionClass<object>  $declaring
     * @return array<string, string> lowercase alias => FQCN
     */
    private static function imports(ReflectionClass $declaring): array
    {
        $file = $declaring->getFileName();

        if ($file === false) {
            return [];
        }

        return self::$importsByFile[$file] ??= self::parseImports((string) file_get_contents($file));
    }

    /**
     * Top-level `use Foo\Bar;`, `use Foo\Bar as Baz;` and `use Foo\{Bar, Baz as Q};`
     * statements only — function/const imports and closure `use` are skipped.
     *
     * @return array<string, string>
     */
    private static function parseImports(string $source): array
    {
        $imports = [];
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            $statement = '';
            for ($j = $i + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
                $statement .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            }
            $i = $j;

            $statement = trim(preg_replace('/\s+/', ' ', $statement) ?? '');

            if ($statement === '' || str_starts_with($statement, 'function ') || str_starts_with($statement, 'const ') || str_starts_with($statement, '(')) {
                continue;
            }

            foreach (self::expandGroup($statement) as $clause) {
                [$fqcn, $alias] = array_pad(array_map('trim', preg_split('/\s+as\s+/i', $clause) ?: []), 2, null);
                $fqcn = ltrim((string) $fqcn, '\\');
                $alias ??= substr($fqcn, (int) strrpos('\\' . $fqcn, '\\'));
                $imports[strtolower($alias)] = $fqcn;
            }
        }

        return $imports;
    }

    /** @return list<string> one `Fqcn[ as Alias]` clause per imported name */
    private static function expandGroup(string $statement): array
    {
        if (preg_match('/^(.*?)\\\\?\{(.*)\}$/', $statement, $m) !== 1) {
            return array_map('trim', explode(',', $statement));
        }

        $prefix = rtrim($m[1], '\\');

        return array_map(static fn (string $part): string => $prefix . '\\' . trim($part), explode(',', $m[2]));
    }
}
