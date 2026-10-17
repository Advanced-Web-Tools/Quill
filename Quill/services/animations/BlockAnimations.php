<?php
namespace Quill\services\animations;

use Quill\services\conversion\quillToUrpc\ConversionContext;
use Quill\services\conversion\quillToUrpc\QuillContentSource;

final class BlockAnimations
{
    /** Missing/deleted library records are intentionally ignored, including on published pages. */
    public static function apply(array $blocks, \stdClass $input, QuillContentSource $source, ConversionContext $context, string $path): array
    {
        $bindings = AnimationLibrary::validateBindings($input->animations ?? []);
        if (!$bindings) return $blocks;
        $settings = (object) ['animations' => new \stdClass(), 'animation_bindings' => []];
        foreach ($bindings as $index => $binding) {
            $definition = $context->animationDefinition($source, $binding->animation_id);
            if ($definition === null) continue;
            $definition = clone $definition; $definition->id = 'qdb-' . $binding->animation_id;
            $settings->animations->{$definition->id} = $definition;
            $settings->animation_bindings[] = (object) ['id' => 'qdb-binding-' . $index, 'animationId' => $definition->id, 'trigger' => $binding->trigger,
                'target' => (object) ['section' => 'target', 'path' => [0]]];
        }
        $compiled = AnimationDefinitions::compile($settings, $context->sourceNamespace . '/' . $path);
        if ($compiled['css'] !== '') $context->addCss($compiled['css']);
        if ($compiled['click']) $context->collectAssets((object) ['assets' => (object) ['scripts' => ['/awt_packages/Quill/views/assets/js/quill-animation-runtime.js']]], $path);
        foreach ($blocks as $block) {
            $attributes = $block->getBlockAttributes();
            foreach ($compiled['targets'][json_encode(['target', [0]])] ?? [] as $binding) {
                $attributes['class'] = trim(($attributes['class'] ?? '') . ' ' . $binding['class']);
                if ($binding['trigger'] === 'click') $attributes['data-quill-animation-trigger'] = 'click';
            }
            $block->setBlockAttributes($attributes);
        }
        return $blocks;
    }
}
