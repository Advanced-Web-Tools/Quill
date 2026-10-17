<?php

namespace Quill\services\conversion\quillToUrpc;

use Quill\services\conversion\shared\ConversionException;
use Quill\services\conversion\shared\ConversionInput;

use URPC\api\URPCBlock;
use URPC\api\URPCBlockDescriptor;
use URPC\api\contracts\IURPCBlock;

final class QuillBlockConverter
{
    public function __construct(
        private QuillComponentExpander $components,
        private QuillStyleConverter $styles,
        private QuillBlockFeatures $features
    ) {}

    /** @return list<IURPCBlock> */
    public function convert(
        mixed $reference,
        QuillContentSource $source,
        ConversionContext $context,
        string $path,
        QuillDocumentConverter $documents,
        array $animationTargets = [], array $animationPath = [], string $section = ''
    ): array {
        $blockId = ConversionInput::id($reference, $path);
        $path .= '/block[' . $blockId . ']';
        $identity = $source->getScopeKey() . '/block/' . $blockId;
        $context->enter($identity, $path);
        try {
            $input = $context->block($source, $blockId, $path);
            ConversionInput::schema($input, $path);
            \Quill\services\animations\AnimationLibrary::validateBindings($input->animations ?? []);
            $kind = $input->kind ?? null;
            if ($kind === 'override') {
                throw ConversionException::at($path, 'Unsupported operation: kind=override requires an original URPC document and the separate override pipeline.');
            }
            if ($kind === 'component') {
                $result = $this->components->expand($input, $source, $context, $path, $documents);
                return \Quill\services\animations\BlockAnimations::apply($this->animationTargets($result, $animationTargets, $animationPath, $section), $input, $source, $context, $path);
            }
            if ($kind !== 'element') {
                throw ConversionException::at($path . '/kind', 'Unknown block kind; expected element or component.');
            }
            return \Quill\services\animations\BlockAnimations::apply($this->animationTargets([$this->element($input, $source, $context, $path, $documents, $animationTargets, $animationPath, $section)], $animationTargets, $animationPath, $section), $input, $source, $context, $path);
        } finally {
            $context->leave($identity);
        }
    }

    private function element(
        \stdClass $input,
        QuillContentSource $source,
        ConversionContext $context,
        string $path,
        QuillDocumentConverter $documents,
        array $animationTargets, array $animationPath, string $section
    ): IURPCBlock {
        $input = $context->scopeBlock($input);
        $this->features->validate($input, $path);
        $descriptor = ConversionInput::object($input->descriptor ?? null, $path . '/descriptor');
        $tag = $descriptor->name ?? null;
        $renderer = ConversionInput::field($descriptor, 'renderer', '');
        if (!is_string($tag) || !preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D', $tag) || !is_string($renderer)) {
            throw ConversionException::at($path . '/descriptor', 'Expected an HTML tag name and a string renderer.');
        }
        $props = ConversionInput::object(ConversionInput::field($descriptor, 'props', new \stdClass()), $path . '/descriptor/props');
        if (ltrim($renderer, '\\') === \Quill\services\data\DataConnection::RENDERER) {
            \Quill\services\data\DataConnection::parse(get_object_vars($props));
        }
        $attributes = $this->attributes(ConversionInput::field($input, 'attributes', new \stdClass()), $path . '/attributes');
        if (($input->definition_key ?? '') === 'Quill:icon' || !empty($attributes['data-quill-icon'])) {
            $context->collectAssets((object) ['assets' => (object) ['styles' => [\Quill\services\icons\FontAwesome::STYLESHEET]]], $path);
        }
        $content = ConversionInput::field($input, 'content', '');
        $children = ConversionInput::orderedList(ConversionInput::field($input, 'children', []), $path . '/children');
        if (!is_string($content)) throw ConversionException::at($path . '/content', 'Content must be a string.');
        $this->validateContent($tag, $content, $children, $path);
        $attributes = \Quill\services\actions\ActionDefinitions::attributes($input, $attributes);
        $styles = isset($input->editor->styleBreakpoints)
            ? get_object_vars(ConversionInput::object($input->editor->styleBreakpoints, $path . '/editor/styleBreakpoints'))
            : $context->componentBreakpoints();
        $attributes = ($styles === null ? $this->styles : new QuillStyleConverter($styles))->apply($input, $attributes, $context, $path);
        $blockDescriptor = new URPCBlockDescriptor();
        $blockDescriptor->setSourceKey($context->sourceNamespace);
        $blockDescriptor->setBlockName($tag);
        // Only the top-level map is converted: nested {} and [] retain their JSON types.
        $blockDescriptor->setBlockProps(get_object_vars($props));
        $blockDescriptor->setBlockRenderer($renderer);
        $block = new URPCBlock($blockDescriptor);
        $block->setBlockAttributes($attributes);
        $block->setBlockContent($content);
        $context->recordBlockSource($block, $input);
        $context->enterFonts(\Quill\services\fonts\GoogleFonts::groups($input), $tag, $content);
        try {
            foreach ($children as $index => $reference) {
                foreach ($this->convert($reference, $source, $context, $path . '/children/' . $index, $documents, $animationTargets, [...$animationPath, $index], $section) as $child) $block->addBlockChild($child);
            }
        } finally { $context->leaveFonts(); }
        return $block;
    }

    /** Render-only attributes: never written into canonical Quill blocks. */
    private function animationTargets(array $blocks, array $targets, array $indices, string $section): array
    {
        foreach ($targets[json_encode([$section, $indices])] ?? [] as $binding) foreach ($blocks as $block) {
            $attributes = $block->getBlockAttributes();
            $attributes['class'] = trim(($attributes['class'] ?? '') . ' ' . $binding['class']);
            if ($binding['trigger'] === 'click') $attributes['data-quill-animation-trigger'] = 'click';
            $block->setBlockAttributes($attributes);
        }
        return $blocks;
    }

    /** @return array<string, scalar|null> */
    private function attributes(mixed $input, string $path): array
    {
        $attributes = get_object_vars(ConversionInput::object($input, $path));
        foreach ($attributes as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_:][A-Za-z0-9_.:-]*$/D', $name)
                || (!is_scalar($value) && $value !== null)) {
                throw ConversionException::at($path . '/' . $name, 'Expected an HTML attribute name and a scalar or null value.');
            }
        }
        return $attributes;
    }

    /** @param list<mixed> $children */
    private function validateContent(string $tag, string $content, array $children, string $path): void
    {
        if ($content === '' && $children === []) return;
        $tag = strtolower($tag);
        if (in_array($tag, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'], true)) {
            throw ConversionException::at($path, "Void element {$tag} cannot have content or children.");
        }
        if (in_array($tag, ['script', 'style'], true)) {
            throw ConversionException::at($path, 'Non-empty script/style content or children are unsupported.');
        }
    }
}
