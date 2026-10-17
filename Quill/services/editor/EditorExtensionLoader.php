<?php

namespace Quill\services\editor;

use event\EventDispatcher;
use Quill\api\editor\BlockRegistry;
use Quill\api\editor\PropertyRegistry;
use Quill\api\events\EditorBlocksRequestEvent;
use Quill\api\events\EditorPropertiesRequestEvent;

final class EditorExtensionLoader
{
    public function __construct(private string $assetBase = '/awt_packages/Quill/views/assets') {}

    /** @return array{blocks: list<array<string, mixed>>, properties: list<array<string, mixed>>} */
    public function load(EventDispatcher $dispatcher): array
    {
        $blocks = new BlockRegistry();
        $properties = new PropertyRegistry();
        // A request-local dispatcher keeps default listeners first without mutating global listeners.
        $dispatcher = clone $dispatcher;
        $defaults = new DefaultEditorDefinitions($this->assetBase);
        foreach ([EditorBlocksRequestEvent::NAME, EditorPropertiesRequestEvent::NAME] as $eventName) {
            $dispatcher->listeners[$eventName] = [$defaults, ...($dispatcher->listeners[$eventName] ?? [])];
        }
        $dispatcher->dispatch(new EditorBlocksRequestEvent($blocks));
        $dispatcher->dispatch(new EditorPropertiesRequestEvent($properties));
        $compiler = new EditorBlockCompiler();
        return [
            'blocks' => array_map($compiler->compile(...), $blocks->all()),
            'properties' => array_map(static fn ($definition): array => [
                'key' => $definition->key, 'category' => $definition->category,
                'categoryLabel' => $definition->categoryLabel ?? ucfirst($definition->category),
                'icon' => $definition->icon, 'condition' => $definition->condition,
                'elements' => array_map(static fn ($element): array => ['definition' => $element->definition, 'condition' => $element->condition], $definition->elements),
            ], $properties->all()),
        ];
    }
}
