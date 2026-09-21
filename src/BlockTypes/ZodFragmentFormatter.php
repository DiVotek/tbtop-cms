<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

/**
 * Reformats one compact zod fragment (as ZodConverter renders it — every
 * `z.object({...})` on one line) into the repo's multi-line object-literal
 * style: one field per line, 4-space indent per nesting level, trailing
 * comma. Operates on the rendered string rather than the PHPDoc AST, so it
 * stays independent of the mapping table — it only ever sees brackets,
 * commas, and quoted string literals (block/alias/enum names never contain
 * braces). A bracket stack tracks `{`/`(`/`[` separately: only a comma whose
 * innermost enclosing bracket is `{` gets broken onto its own line — a plain
 * call's argument list, e.g. `z.record(z.string(), z.unknown())`, has no `{`
 * around it and stays inline.
 */
final class ZodFragmentFormatter
{
    public static function format(string $fragment, int $baseIndent): string
    {
        $out = '';
        $depth = $baseIndent;
        /** @var list<string> */
        $stack = [];
        $inString = false;
        $length = strlen($fragment);

        for ($i = 0; $i < $length; $i++) {
            $char = $fragment[$i];

            if ($inString) {
                $out .= $char;
                if ($char === '\\') {
                    $out .= $fragment[++$i];
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                $out .= $char;

                continue;
            }

            if ($char === '{' || $char === '(' || $char === '[') {
                $stack[] = $char;

                if ($char === '{' && self::nextNonSpace($fragment, $i + 1) !== '}') {
                    $depth++;
                    $out .= "{\n" . str_repeat(' ', $depth * 4);
                    $i = self::skipSpace($fragment, $i + 1) - 1;

                    continue;
                }

                $out .= $char;

                continue;
            }

            if ($char === '}' || $char === ')' || $char === ']') {
                array_pop($stack);

                if ($char === '}' && self::lastNonSpace($out) !== '{') {
                    $depth--;
                    $out = rtrim($out) . ",\n" . str_repeat(' ', $depth * 4) . '}';

                    continue;
                }

                $out .= $char;

                continue;
            }

            if ($char === ',' && end($stack) === '{' && self::nextNonSpace($fragment, $i + 1) !== '}') {
                $out .= ",\n" . str_repeat(' ', $depth * 4);
                $i = self::skipSpace($fragment, $i + 1) - 1;

                continue;
            }

            if ($char === ' ' && (self::lastNonSpace($out) === '{' || $out === '' || str_ends_with(rtrim($out), "\n"))) {
                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    private static function nextNonSpace(string $s, int $from): string
    {
        while ($from < strlen($s) && $s[$from] === ' ') {
            $from++;
        }

        return $s[$from] ?? '';
    }

    private static function skipSpace(string $s, int $from): int
    {
        while ($from < strlen($s) && $s[$from] === ' ') {
            $from++;
        }

        return $from;
    }

    private static function lastNonSpace(string $s): string
    {
        return rtrim($s) === '' ? '' : substr(rtrim($s), -1);
    }
}
