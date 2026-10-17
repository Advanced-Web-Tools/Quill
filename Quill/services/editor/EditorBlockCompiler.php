<?php

namespace Quill\services\editor;

use Quill\api\editor\BlockDefinition;
use Quill\services\conversion\urpcToQuill\ConversionResult;
use Quill\services\conversion\urpcToQuill\UrpcToQuillConverter;
use URPC\api\contracts\IURPCBlock;
use URPC\api\URPCDocument;

final class EditorBlockCompiler
{
    /** @return array<string, mixed> */
    public function compile(BlockDefinition $definition): array
    {
        $converter = new UrpcToQuillConverter();
        $content = $definition->content;
        if ($content instanceof IURPCBlock) {
            $document = new URPCDocument();
            $document->addBlock('main', $content);
            $content = $document;
        }
        try {
            $result = is_string($content) ? $converter->convertJson($content) : $converter->convert($content);
        } catch (\Quill\services\conversion\shared\ConversionException|\JsonException $error) {
            throw new \InvalidArgumentException('Invalid editor block ' . $definition->key . ': ' . $error->getMessage(), 0, $error);
        }
        $roots = [];
        foreach ($result->document->sections as $section) {
            foreach ($section->blocks as $reference) {
                $block = $this->expand($reference, $result);
                $block->_quill->definition_key = $definition->key;
                $block->_quill->editor->label = $definition->name;
                $roots[] = $block;
            }
        }
        if ($roots === []) throw new \InvalidArgumentException('Block definition has no root blocks: ' . $definition->key);
        return ['key' => $definition->key, 'name' => $definition->name, 'icon' => $definition->icon, 'description' => $definition->description, 'category' => $definition->category, 'blocks' => $roots];
    }

    private function expand(int $reference, ConversionResult $result): \stdClass
    {
        $block = $result->blocks[$reference];
        return (object) [
            'descriptor' => $block->descriptor, 'attributes' => $block->attributes, 'content' => $block->content,
            'children' => array_map(fn (int $child): \stdClass => $this->expand($child, $result), $block->children),
            '_quill' => (object) ['styles' => $block->styles, 'bindings' => $block->bindings, 'custom_settings' => $block->custom_settings, 'editor' => $block->editor],
        ];
    }
}
