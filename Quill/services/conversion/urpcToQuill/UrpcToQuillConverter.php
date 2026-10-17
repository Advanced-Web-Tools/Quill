<?php

namespace Quill\services\conversion\urpcToQuill;

use URPC\api\contracts\IURPCDocument;

final class UrpcToQuillConverter
{
    private UrpcDocumentConverter $documents;

    public function __construct(private int $maxDepth = 128)
    {
        if ($maxDepth < 1) throw new \InvalidArgumentException('Maximum conversion depth must be positive.');
        $this->documents = new UrpcDocumentConverter(new UrpcBlockConverter(new UrpcValueConverter()));
    }

    public function convert(IURPCDocument $document): ConversionResult
    {
        $context = new ConversionContext($this->maxDepth);
        $converted = $this->documents->convert($document, $context);
        return new ConversionResult($converted, $context->getBlocks(), $context->getIdentities());
    }

    /** Accepts one URPC loader envelope or the Quill sections transport. Never runs the URPC loader/processors. */
    public function convertJson(string $json, string $sectionName = 'main'): ConversionResult
    {
        $document = (new UrpcJsonReader($this->maxDepth))->read($json, $sectionName);
        return $this->convert($document);
    }
}
