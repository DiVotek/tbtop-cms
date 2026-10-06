<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Closure;
use stdClass;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\BlockRowProblem;

/**
 * "Problem blocks (N)" on the page and layout editors: a modal listing the
 * rows the site skips, each with its saved data and a "Delete row" action.
 *
 * It sits inside the editor's form, not in the page header: only an action
 * in the form's subtree (its modal body included) is sent the form, and the
 * delete goes through the same write as a tab's Remove — the whole form,
 * lock_version included.
 */
final class ProblemBlocksAction
{
    /**
     * @param  list<array{id: string, kind: string, reason: BlockRowProblem, shared: int|null, data: array<string, mixed>}>  $problems
     * @param  bool  $skipsValidation  the editor's own rule for its structural actions (a tab's Remove)
     * @param  Closure(ActionCtx, string): Effects  $delete  removes the row with this id through the editor's write path
     */
    public static function build(S $s, string $prefix, array $problems, bool $skipsValidation, Closure $delete): ?ActionBuilder
    {
        if ($problems === []) {
            return null;
        }

        $registry = app(BlockRegistry::class);
        $rows = array_map(
            static fn (array $problem): Node => self::row($s, $registry, $prefix, $problem, $skipsValidation, $delete),
            $problems,
        );

        return $s->action("{$prefix}ProblemBlocks")
            ->label(__('tbtop-cms::pages.problem_blocks.action', ['count' => count($problems)]))
            ->color('danger')->outlined()
            ->modal(
                __('tbtop-cms::pages.problem_blocks.title'),
                $s->stack([
                    $s->displayText(__('tbtop-cms::pages.problem_blocks.description'))->variant('muted'),
                    $s->stack($rows, ['gap' => 3]),
                ]),
            )
            ->modalWidth('2xl');
    }

    /**
     * @param  array{id: string, kind: string, reason: BlockRowProblem, shared: int|null, data: array<string, mixed>}  $problem
     * @param  Closure(ActionCtx, string): Effects  $delete
     */
    private static function row(S $s, BlockRegistry $registry, string $prefix, array $problem, bool $skipsValidation, Closure $delete): Node
    {
        $id = $problem['id'];
        $deleteAction = $s->action("{$prefix}ProblemDelete_{$id}")
            ->label(__('tbtop-cms::pages.problem_blocks.delete'))->color('danger')
            ->confirm(__('tbtop-cms::pages.problem_blocks.delete_title'), __('tbtop-cms::pages.problem_blocks.delete_confirm'))
            ->withoutValidation($skipsValidation)
            ->handle(static fn (ActionCtx $ctx): Effects => $delete($ctx, $id), needs: ['form']);

        return $s->flex([
            $s->flex([
                $s->displayText(BlockPicker::label($registry, $problem['kind']))->variant('subheading'),
                $s->spacer(),
                $deleteAction,
            ], align: 'center', gap: 2),
            $s->displayText(self::reason($problem))->variant('muted'),
            $s->displayHtml(self::dataHtml($problem['data'])),
        ], direction: 'col', gap: 2, variant: 'card');
    }

    /** @param  array{id: string, kind: string, reason: BlockRowProblem, shared: int|null, data: array<string, mixed>}  $problem */
    private static function reason(array $problem): string
    {
        return __('tbtop-cms::pages.problem_blocks.reasons.' . $problem['reason']->value, [
            'kind' => $problem['kind'],
            'id' => (string) $problem['shared'],
        ]);
    }

    /**
     * The saved data as pretty JSON. The DSL has no code node, so it is a <pre>
     * through displayHtml, which renders unsanitised — hence e().
     *
     * @param  array<string, mixed>  $data
     */
    private static function dataHtml(array $data): string
    {
        $json = json_encode($data === [] ? new stdClass : $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return '<pre data-testid="problem-block-data" style="margin:0;padding:8px 12px;max-height:16rem;overflow:auto;font-size:12px;'
            . 'font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap;word-break:break-word">'
            . e((string) $json) . '</pre>';
    }
}
