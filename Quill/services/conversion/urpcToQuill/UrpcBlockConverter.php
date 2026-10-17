<?php

namespace Quill\services\conversion\urpcToQuill;

use Quill\services\conversion\shared\ConversionException;
use URPC\api\contracts\IURPCBlock;

final class UrpcBlockConverter
{
    public function __construct(private UrpcValueConverter $values) {}

    public function convert(mixed $input, ConversionContext $context, string $path): int
    {
        if (!$input instanceof IURPCBlock) throw ConversionException::at($path, 'Expected IURPCBlock.');
        $descriptor = $input->getBlockDescriptor();
        $uid = $descriptor->getBlockUid();
        $path .= '/block[' . ($uid !== '' ? $uid : 'unassigned') . ']';
        $localId = $context->enter($input, $path);
        try {
            $name = $descriptor->getBlockName();
            if (!preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D', $name)) {
                throw ConversionException::at($path . '/descriptor/name', 'Expected an HTML tag name.');
            }
            $content = $this->values->text($input->getBlockContent(), $path . '/content');
            $renderer = $this->values->text($descriptor->getBlockRenderer(), $path . '/descriptor/renderer');
            $children = $input->getBlockChildren();
            $this->validateContent($name, $content, $children, $path);
            $props = $this->values->object($descriptor->getBlockProps(), $path . '/descriptor/props');
            $attributes = $this->attributes($input->getBlockAttributes(), $path . '/attributes');
            $childIds = [];
            foreach (array_values($children) as $index => $child) {
                $childIds[] = $this->convert($child, $context, $path . '/children/' . $index);
            }
            $block = (object) [
                'schema_version' => 1,
                'kind' => 'element',
                'descriptor' => (object) ['name' => $name, 'props' => $props, 'renderer' => $renderer],
                'attributes' => $attributes,
                'content' => $content,
                'children' => $childIds,
                'styles' => (object) ['base' => new \stdClass(), 'tablet' => new \stdClass(), 'mobile' => new \stdClass()],
                'bindings' => new \stdClass(),
                'custom_settings' => new \stdClass(),
                'editor' => (object) ['label' => $name, 'locked' => false],
            ];
            // URPC identity is retained separately, never used as a Quill database ID.
            $context->addBlock($localId, $block, $uid, $descriptor->getSourceKey());
            return $localId;
        } finally {
            $context->leave($input);
        }
    }

    /** @param array<array-key, mixed> $attributes */
    private function attributes(array $attributes, string $path): \stdClass
    {
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_:][A-Za-z0-9_.:-]*$/D', $name)
                || (!is_scalar($value) && $value !== null)) {
                throw ConversionException::at($path . '/' . $name, 'Expected an HTML attribute name and scalar or null value.');
            }
        }
        return $this->values->object($attributes, $path);
    }

    /** @param array<array-key, mixed> $children */
    private function validateContent(string $name, string $content, array $children, string $path): void
    {
        if ($content === '' && $children === []) return;
        $tag = strtolower($name);
        if (in_array($tag, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true)) {
            throw ConversionException::at($path, "Void element {$tag} cannot have content or children.");
        }
        if (in_array($tag, ['script', 'style'], true)) {
            throw ConversionException::at($path, 'Non-empty script/style content or children are unsupported.');
        }
    }
}
