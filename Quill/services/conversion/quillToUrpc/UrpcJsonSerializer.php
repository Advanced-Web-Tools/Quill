<?php

namespace Quill\services\conversion\quillToUrpc;

use Quill\services\conversion\shared\ConversionException;

use URPC\api\contracts\IURPCBlock;
use URPC\api\contracts\IURPCDocument;

final class UrpcJsonSerializer
{
    /** Quill transport envelope; this is not a URPCLoader input. */
    public function serializeDocument(IURPCDocument $document): string
    {
        $sections = [];
        foreach ($document->getSections() as $name => $blocks) {
            $sections[] = ['name' => $name, 'blocks' => $this->blocks($blocks)];
        }
        return json_encode(['schema_version' => 1, 'sections' => $sections], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** URPCLoader-compatible export of one named section. */
    public function serializeSection(IURPCDocument $document, string $section): string
    {
        if (!array_key_exists($section, $document->getSections())) {
            throw ConversionException::at('sections/' . $section, 'Section does not exist.');
        }
        return json_encode(['blocks' => $this->blocks($document->getBlocks($section))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<array-key, IURPCBlock> $blocks @return list<array<string, mixed>> */
    private function blocks(array $blocks): array
    {
        $result = [];
        foreach ($blocks as $block) {
            $descriptor = $block->getBlockDescriptor();
            $result[] = [
                'descriptor' => [
                    'name' => $descriptor->getBlockName(),
                    'props' => (object) $descriptor->getBlockProps(),
                    'renderer' => $descriptor->getBlockRenderer(),
                ],
                'attributes' => (object) $block->getBlockAttributes(),
                'content' => $block->getBlockContent(),
                'children' => $this->blocks($block->getBlockChildren()),
            ];
        }
        return $result;
    }
}
