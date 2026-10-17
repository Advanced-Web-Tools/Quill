<?php

namespace Quill\services\conversion\quillToUrpc;

use URPC\api\contracts\IURPCDocument;

final class ConversionResult
{
    /** @param array{styles: list<string>, scripts: list<string>} $assets */
    public function __construct(
        public readonly IURPCDocument $document,
        public readonly array $assets,
        public readonly string $scopedCss,
        /** @var array<int, \stdClass> Optional source metadata; never emitted by UrpcJsonSerializer. */
        public readonly array $blockSources = [],
        public readonly array $actions = [],
        public readonly array $actionBreakpoints = []
    ) {}
}
