<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

use InvalidArgumentException;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Form\Support\FormActionProps;
use Tbtop\Cms\Page\Models\Page;

/**
 * Every registered block that uses {@see HasForm}, keyed by its form name.
 * Built once from the BlockRegistry on first use. The submit route resolves a
 * name here when no block row on the page has that id, so a form rendered by
 * a template posts from any live page.
 */
class Forms
{
    /** @var array<string, Block&HandlesFormSubmission>|null */
    private ?array $forms = null;

    public function __construct(private readonly BlockRegistry $registry) {}

    /** @return array<string, Block&HandlesFormSubmission> */
    public function all(): array
    {
        return $this->forms ??= $this->collect();
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): Block & HandlesFormSubmission
    {
        return $this->all()[$name] ?? throw new InvalidArgumentException("Form [{$name}] is not registered.");
    }

    /** The submit URL for this form posted from `$page`. */
    public function action(string $name, Page $page, ?string $locale = null): string
    {
        return $this->props($name, $page, $locale)['action'];
    }

    /**
     * The props a frontend form needs: `action`, plus `honeypotField` when
     * one is configured — the same pair FormActionBinder gives a placed block.
     *
     * @return array{action: string, honeypotField?: string}
     */
    public function props(string $name, Page $page, ?string $locale = null): array
    {
        $this->get($name);

        return FormActionProps::for($page, $name, $locale);
    }

    /** @return array<string, Block&HandlesFormSubmission> */
    private function collect(): array
    {
        $forms = [];

        foreach ($this->registry->all() as $block) {
            if (! $block instanceof HandlesFormSubmission || ! in_array(HasForm::class, class_uses_recursive($block), true)) {
                continue;
            }

            $name = method_exists($block, 'formName') ? (string) $block->formName() : $block->kind();

            if (isset($forms[$name])) {
                throw new InvalidArgumentException("Form [{$name}] is declared by both [" . $forms[$name]::class . '] and [' . $block::class . '].');
            }

            $forms[$name] = $block;
        }

        return $forms;
    }
}
