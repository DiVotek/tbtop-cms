<?php

declare(strict_types=1);

namespace Tbtop\Cms\BlockTypes;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use RuntimeException;
use Tbtop\Cms\Page\Enums\PageStatus;

/**
 * Assembles pages.generated.ts: one `page` variant per registered type and
 * subtype, discriminated by the literal pair (type, subtype), their union
 * `cmsPage`, and `pageProps` keyed by type or subtype key.
 */
final class PageTypeScriptEmitter
{
    private const array RESERVED = ['pageCommon', 'cmsPage', 'CmsPage', 'pageProps', 'PageOf', ZodConverter::EMPTY_ARRAY_AS_OBJECT];

    /**
     * The `page` prop's keys other than type, subtype and fields, as
     * PageController sends them; a test holds the two key sets together.
     *
     * @return array<string, string> key => zod fragment
     */
    public static function commonFields(): array
    {
        $statuses = array_map(static fn (PageStatus $status): string => json_encode($status->value, JSON_THROW_ON_ERROR), PageStatus::cases());

        return [
            'id' => 'z.union([z.string(), z.number()])',
            'status' => 'z.enum([' . implode(', ', $statuses) . '])',
            'name' => 'z.string().nullable()',
            'title' => 'z.string().nullable()',
            'heading' => 'z.string().nullable()',
            'summary' => 'z.string().nullable()',
            'body' => 'z.string()',
            'image' => 'z.string().nullable()',
        ];
    }

    /**
     * @param  list<PageShape>  $shapes  in registry order
     * @param  array<string, TypeNode>  $aliases
     */
    public function emit(array $shapes, array $aliases): string
    {
        $aliasFragments = ZodModule::aliasFragments(array_map(static fn (PageShape $shape): array => $shape->usedAliases, $shapes), $aliases);
        $fieldsFragments = array_map($this->fieldsFragment(...), $shapes);
        $this->assertDistinctExportNames($shapes, array_keys($aliasFragments));

        $lines = ZodModule::header([...$fieldsFragments, ...array_values($aliasFragments)]);

        foreach ($aliasFragments as $name => $fragment) {
            array_push($lines, ...ZodModule::aliasConstAndType($name, $fragment));
            $lines[] = '';
        }

        $lines[] = 'const pageCommon = {';
        foreach (self::commonFields() as $key => $fragment) {
            $lines[] = "    {$key}: {$fragment},";
        }
        $lines[] = '};';
        $lines[] = '';

        foreach ($shapes as $index => $shape) {
            $fields = EmitterNames::pageConst($shape->key, 'Fields');
            $page = EmitterNames::pageConst($shape->key, 'Page');
            $subtype = $shape->subtype === null ? 'z.null()' : 'z.literal(' . $this->quote($shape->subtype) . ')';
            $lines[] = "export const {$fields} = " . ZodFragmentFormatter::format($fieldsFragments[$index], 0) . ';';
            $lines[] = 'export type ' . EmitterNames::pageType($shape->key, 'Fields') . " = z.infer<typeof {$fields}>;";
            $lines[] = '';
            $lines[] = "export const {$page} = " . ZodFragmentFormatter::format('z.object({ ...pageCommon, type: z.literal(' . $this->quote($shape->type) . "), subtype: {$subtype}, fields: {$fields} })", 0) . ';';
            $lines[] = 'export type ' . EmitterNames::pageType($shape->key, 'Page') . " = z.infer<typeof {$page}>;";
            $lines[] = '';
        }

        $pages = array_map(static fn (PageShape $shape): string => EmitterNames::pageConst($shape->key, 'Page'), $shapes);
        $lines[] = 'export const cmsPage = z.union([' . implode(', ', $pages) . ']);';
        $lines[] = 'export type CmsPage = z.infer<typeof cmsPage>;';
        $lines[] = '';
        $lines[] = 'export const pageProps = {';
        foreach ($shapes as $index => $shape) {
            $lines[] = '    ' . EmitterNames::objectKey($shape->key) . ': ' . $pages[$index] . ',';
        }
        $lines[] = '} as const;';
        $lines[] = 'export type PageOf<K extends keyof typeof pageProps> = z.infer<(typeof pageProps)[K]>;';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** `page.fields` is sent as an object even when empty, so no empty-array wrapper here. */
    private function fieldsFragment(PageShape $shape): string
    {
        $entries = [];
        foreach ($shape->fields as $key => $fragment) {
            $entries[] = EmitterNames::objectKey($key) . ": {$fragment}";
        }

        return ($shape->isLoose ? 'z.looseObject' : 'z.object') . '({ ' . implode(', ', $entries) . ' })';
    }

    /**
     * @param  list<PageShape>  $shapes
     * @param  list<string>  $aliasNames
     */
    private function assertDistinctExportNames(array $shapes, array $aliasNames): void
    {
        $owners = array_fill_keys(self::RESERVED, 'the generated file itself');

        $exports = [];
        foreach ($shapes as $shape) {
            foreach (['Fields', 'Page'] as $suffix) {
                $exports[] = [EmitterNames::pageConst($shape->key, $suffix), "page type [{$shape->key}]"];
                $exports[] = [EmitterNames::pageType($shape->key, $suffix), "page type [{$shape->key}]"];
            }
        }
        foreach ($aliasNames as $alias) {
            $exports[] = [EmitterNames::aliasConst($alias), "type alias [{$alias}]"];
            $exports[] = [EmitterNames::propsType($alias), "type alias [{$alias}]"];
        }

        foreach ($exports as [$name, $owner]) {
            if (isset($owners[$name]) && $owners[$name] !== $owner) {
                throw new RuntimeException("{$owners[$name]} and {$owner} would both declare `{$name}` in the page types file; rename one of them.");
            }

            $owners[$name] = $owner;
        }
    }

    private function quote(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
