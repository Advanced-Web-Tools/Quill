<?php

namespace Quill\services\conversion\urpcToQuill;

use Quill\services\conversion\shared\ConversionException;
use URPC\api\contracts\IURPCDocument;

final class UrpcDocumentConverter
{
    public function __construct(private UrpcBlockConverter $blocks) {}

    public function convert(IURPCDocument $document, ConversionContext $context): \stdClass
    {
        $sections = [];
        foreach ($document->getSections() as $name => $blocks) {
            $path = 'document/sections/' . $name;
            if (!is_string($name) || trim($name) === '' || is_numeric($name) || !is_array($blocks)) {
                throw ConversionException::at($path, 'Expected a non-empty, non-numeric section name and an array of blocks.');
            }
            $references = [];
            foreach (array_values($blocks) as $index => $block) {
                $references[] = $this->blocks->convert($block, $context, $path . '/blocks/' . $index);
            }
            $sections[] = (object) ['name' => $name, 'blocks' => $references];
        }
        // Assets and Quill settings have no representation in the URPC document API.
        return (object) [
            'schema_version' => 1,
            'sections' => $sections,
            'assets' => (object) ['styles' => [], 'scripts' => []],
            'settings' => new \stdClass(),
        ];
    }
}
