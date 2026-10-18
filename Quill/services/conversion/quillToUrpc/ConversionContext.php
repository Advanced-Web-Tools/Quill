<?php

namespace Quill\services\conversion\quillToUrpc;

use Quill\services\conversion\shared\ConversionException;
use Quill\services\conversion\shared\ConversionInput;

final class ConversionContext
{
    /** @var array<string, string> Active identities and their first document paths. */
    private array $active = [];
    /** @var array<string, \stdClass> */
    private array $data = [];
    /** @var array<string, QuillContentSource> */
    private array $components = [];
    /** @var array{styles: list<string>, scripts: list<string>} */
    private array $assets = ['styles' => [], 'scripts' => []];
    /** @var list<string> */
    private array $css = [];
    private array $fontStack = [];
    private array $fontRequests = [];

    private array $animationDefinitions = [];
    public function animationDefinition(QuillContentSource $source, int $id): ?\stdClass
    {
        $key = $source->getScopeKey() . '/animation/' . $id;
        if (!array_key_exists($key, $this->animationDefinitions)) $this->animationDefinitions[$key] = $source instanceof AnimationContentSource ? $source->getAnimationDefinition($id) : null;
        return $this->animationDefinitions[$key];
    }

    public function enterFonts(array $groups, string $tag = '', string $content = ''): void
    {
        $parent = end($this->fontStack) ?: ['families' => [], 'weights' => [400], 'italics' => [0]];
        $contexts = \Quill\services\fonts\GoogleFonts::contexts($groups, $parent, $tag);
        $this->fontStack[] = \Quill\services\fonts\GoogleFonts::inherited($contexts);
        if (trim($content) === '') return;
        foreach ($contexts as $context) foreach ($context['families'] as $family) {
            $font = \Quill\services\fonts\GoogleFonts::find($family);
            foreach ($context['weights'] as $weight) foreach ($context['italics'] as $italic) {
                $variant = \Quill\services\fonts\GoogleFonts::variant($font, $weight, $italic);
                $this->fontRequests[$family][$variant] = true;
            }
        }
    }
    public function leaveFonts(): void { array_pop($this->fontStack); }

    /** @var array<int, \stdClass> Original Quill data keyed by the resulting URPC object identity. */
    private array $blockSources = [];
    private array $blockSourceScopes = [];
    private array $actions = [];
    private array $componentScopes = [];
    private array $targetAliases = [];
    public function scopedTarget(string $id): string { return $this->componentScopes ? $this->instanceId($id, 'qt') : $id; }
    public function aliasTarget(string $from, string $to): void { $this->targetAliases[$from] = $to; }


    public function enterComponent(string $instance, ?array $breakpoints): void
    {
        $parent = end($this->componentScopes) ?: null;
        $this->componentScopes[] = ['identity' => ($parent['identity'] ?? '') . '/' . $instance,
            'breakpoints' => $breakpoints ?? ($parent['breakpoints'] ?? null)];
    }
    public function leaveComponent(): void { array_pop($this->componentScopes); }
    public function componentBreakpoints(): ?array { return (end($this->componentScopes) ?: [])['breakpoints'] ?? null; }
    private function instanceId(string $id, string $prefix): string
    {
        return $prefix . '-' . substr(hash('sha256', end($this->componentScopes)['identity'] . '/' . $id), 0, 32);
    }
    public function scopeBlock(\stdClass $block): \stdClass
    {
        if (!$this->componentScopes || !isset($block->editor)) return $block;
        foreach (\Quill\services\actions\ActionDefinitions::BINDINGS as $field) {
            if (isset($block->editor->{$field})) $block->editor->{$field} = $this->instanceId($block->editor->{$field}, 'qa');
        }
        if (isset($block->editor->actionTargetId)) $block->editor->actionTargetId = $this->instanceId($block->editor->actionTargetId, 'qt');
        return $block;
    }


    public function __construct(public readonly string $sourceNamespace, private int $maxDepth)
    {
        if (trim($sourceNamespace) === '' || $maxDepth < 1) {
            throw new \InvalidArgumentException('Provide a non-empty source namespace and positive maximum depth.');
        }
    }

    public function enter(string $identity, string $path): void
    {
        if (isset($this->active[$identity])) {
            throw ConversionException::at($path, "Cycle at {$identity}; already active at {$this->active[$identity]}.");
        }
        if (count($this->active) >= $this->maxDepth) {
            throw ConversionException::at($path, "Maximum conversion depth {$this->maxDepth} exceeded at {$identity}.");
        }
        $this->active[$identity] = $path;
    }

    public function leave(string $identity): void { unset($this->active[$identity]); }

    public function document(QuillContentSource $source, string $path): \stdClass
    {
        $key = $source->getScopeKey() . '/document';
        return $this->data[$key] ??= ConversionInput::decode($source->getDocument($path), $path);
    }

    public function block(QuillContentSource $source, int $blockId, string $path): \stdClass
    {
        $key = $source->getScopeKey() . '/block/' . $blockId;
        $this->data[$key] ??= ConversionInput::decode($source->getBlock($blockId, $path), $path);
        return ConversionInput::copy($this->data[$key]);
    }

    public function component(QuillContentSource $source, int $componentId, string $path): QuillContentSource
    {
        $key = $source->getScopeKey() . '/component/' . $componentId;
        return $this->components[$key] ??= $source->getComponent($componentId, $path);
    }

    public function collectAssets(\stdClass $document, string $path): void
    {
        $assets = ConversionInput::object(ConversionInput::field($document, 'assets', new \stdClass()), $path . '/assets');
        if (array_diff(array_keys(get_object_vars($assets)), ['styles', 'scripts'])) {
            throw ConversionException::at($path . '/assets', 'Unsupported asset category.');
        }
        foreach (['styles', 'scripts'] as $type) {
            foreach (ConversionInput::orderedList(ConversionInput::field($assets, $type, []), $path . '/assets/' . $type) as $asset) {
                if (!is_string($asset) || trim($asset) === '') {
                    throw ConversionException::at($path . '/assets/' . $type, 'Expected non-empty asset strings.');
                }
                if (!in_array($asset, $this->assets[$type], true)) $this->assets[$type][] = $asset;
            }
        }
    }

    public function collectActions(\stdClass $document): void
    {
        foreach (\Quill\services\actions\ActionDefinitions::catalog($document->settings->actions ?? []) as $original) {
            $action = ConversionInput::copy($original);
            if ($this->componentScopes) {
                $action->id = $this->instanceId($action->id, 'qa');
                foreach ($action->steps as $step) {
                    $step->id = $this->instanceId($step->id, 'qs');
                    if ($step->target->type === 'element') $step->target->value = $this->instanceId($step->target->value, 'qt');
                }
            }
            if (isset($this->actions[$action->id]) && $this->actions[$action->id] != $action) throw new \InvalidArgumentException('Conflicting action identity.');
            $this->actions[$action->id] = $action;
        }
    }
    public function getActions(): array
    {
        $actions = ConversionInput::copy(array_values($this->actions));
        foreach ($actions as $action) foreach ($action->steps as $step) {
            if ($step->target->type !== 'element') continue;
            $seen = [];
            while (isset($this->targetAliases[$step->target->value]) && !isset($seen[$step->target->value])) {
                $seen[$step->target->value] = true;
                $step->target->value = $this->targetAliases[$step->target->value];
            }
        }
        return $actions;
    }
    public function validateActionBindings(): void
    {
        foreach ($this->blockSources as $block) {
            foreach (\Quill\services\actions\ActionDefinitions::BINDINGS as $trigger => $field) {
                if (!isset($block->editor->{$field})) continue;
                $action = $this->actions[$block->editor->{$field}] ?? null;
                if ($action === null || $action->trigger !== $trigger) throw new \InvalidArgumentException('Action does not exist or its trigger does not match the binding.');
            }
        }
    }

    public function addCss(string $css): void { $this->css[] = $css; }
    /** @return array{styles: list<string>, scripts: list<string>} */
    public function getAssets(): array
    {
        $assets = $this->assets;
        ksort($this->fontRequests);
        foreach ($this->fontRequests as $family => $variants) {
            $url = \Quill\services\fonts\GoogleFonts::url($family, array_keys($variants));
            if (!in_array($url, $assets['styles'], true)) $assets['styles'][] = $url;
        }
        return $assets;
    }
    public function getCss(): string { return implode("\n", $this->css); }
    public function recordBlockSource(\URPC\api\contracts\IURPCBlock $block, \stdClass $source, string $scope = ''): void
    {
        $this->blockSources[spl_object_id($block)] = ConversionInput::copy($source);
        $this->blockSourceScopes[spl_object_id($block)] = $scope;
    }
    /** @return array<int, string> */
    public function getBlockSourceScopes(): array { return $this->blockSourceScopes; }
    /** @return array<int, \stdClass> */
    public function getBlockSources(): array { return $this->blockSources; }
}
