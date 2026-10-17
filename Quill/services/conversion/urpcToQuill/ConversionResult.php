<?php

namespace Quill\services\conversion\urpcToQuill;

use Quill\services\conversion\shared\ConversionException;

/** In-memory import bundle. Local references must be remapped if persisted later. */
final class ConversionResult
{
    /**
     * @param array<int, \stdClass> $blocks Block documents indexed by local conversion ID.
     * @param array<int, array{uid: string, source_key: string}> $identities Original URPC identities.
     */
    public function __construct(
        public readonly \stdClass $document,
        public readonly array $blocks,
        public readonly array $identities
    ) {}

    public function serializeDocument(): string
    {
        return json_encode($this->document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function serializeBlock(int $localId): string
    {
        $block = $this->blocks[$localId] ?? throw ConversionException::at('blocks/' . $localId, 'Local block does not exist.');
        return json_encode($block, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
