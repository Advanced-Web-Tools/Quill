<?php

namespace Quill\services\conversion\urpcToQuill;

use Quill\services\conversion\shared\ConversionException;
use Quill\services\conversion\shared\ConversionInput;
use URPC\api\URPCBlock;
use URPC\api\URPCBlockDescriptor;
use URPC\api\URPCDocument;
use URPC\api\contracts\IURPCBlock;
use URPC\api\contracts\IURPCDocument;

/** Reads transport structure only, without URPCLoader's identity assignment or lossy nested map conversion. */
final class UrpcJsonReader
{
    public function __construct(private int $maxDepth) {}

    public function read(string $json, string $sectionName): IURPCDocument
    {
        $input = ConversionInput::decode($json, 'document');
        if (property_exists($input, 'blocks') && property_exists($input, 'sections')) {
            throw ConversionException::at('document', 'Ambiguous input: provide blocks or sections, not both.');
        }
        if (property_exists($input, 'blocks')) {
            $this->knownFields($input, ['blocks'], 'document');
            $sections = [(object) ['name' => $sectionName, 'blocks' => $input->blocks]];
        } else {
            $this->knownFields($input, ['schema_version', 'sections'], 'document');
            ConversionInput::schema($input, 'document');
            $sections = ConversionInput::orderedList($input->sections ?? null, 'document/sections');
        }
        $document = new URPCDocument();
        $names = [];
        foreach ($sections as $index => $section) {
            $path = 'document/sections/' . $index;
            $section = ConversionInput::object($section, $path);
            $this->knownFields($section, ['name', 'blocks'], $path);
            $name = $section->name ?? null;
            if (!is_string($name) || trim($name) === '' || is_numeric($name) || in_array($name, $names, true)) {
                throw ConversionException::at($path . '/name', 'Expected a unique non-empty, non-numeric section name.');
            }
            $names[] = $name;
            $document->addSection($name);
            $blocks = ConversionInput::orderedList($section->blocks ?? null, $path . '/blocks');
            foreach ($blocks as $position => $block) {
                $document->addBlock($name, $this->block($block, $path . '/blocks/' . $position, 0));
            }
        }
        return $document;
    }

    private function block(mixed $input, string $path, int $depth): IURPCBlock
    {
        if ($depth >= $this->maxDepth) throw ConversionException::at($path, 'Maximum conversion depth exceeded.');
        $input = ConversionInput::object($input, $path);
        $this->knownFields($input, ['descriptor', 'attributes', 'content', 'children'], $path);
        $descriptor = ConversionInput::object($input->descriptor ?? null, $path . '/descriptor');
        $this->knownFields($descriptor, ['name', 'props', 'renderer'], $path . '/descriptor');
        $name = $descriptor->name ?? null;
        $renderer = ConversionInput::field($descriptor, 'renderer', '');
        $content = ConversionInput::field($input, 'content', '');
        if (!is_string($name) || !is_string($renderer) || !is_string($content)) {
            throw ConversionException::at($path, 'Expected string descriptor.name, descriptor.renderer and content.');
        }
        $props = ConversionInput::object(ConversionInput::field($descriptor, 'props', new \stdClass()), $path . '/descriptor/props');
        $attributes = ConversionInput::object(ConversionInput::field($input, 'attributes', new \stdClass()), $path . '/attributes');
        $children = ConversionInput::orderedList(ConversionInput::field($input, 'children', []), $path . '/children');
        $block = new URPCBlock(new URPCBlockDescriptor($name, get_object_vars($props), $renderer));
        $block->setBlockAttributes(get_object_vars($attributes));
        $block->setBlockContent($content);
        foreach ($children as $index => $child) $block->addBlockChild($this->block($child, $path . '/children/' . $index, $depth + 1));
        return $block;
    }

    /** @param list<string> $allowed */
    private function knownFields(\stdClass $input, array $allowed, string $path): void
    {
        $unknown = array_diff(array_keys(get_object_vars($input)), $allowed);
        if ($unknown !== []) {
            throw ConversionException::at($path, 'Unsupported transport fields: ' . implode(', ', $unknown) . '.');
        }
    }
}
