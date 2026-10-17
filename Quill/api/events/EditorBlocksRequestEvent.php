<?php

namespace Quill\api\events;

use event\interfaces\IEvent;
use Quill\api\editor\BlockRegistry;

final class EditorBlocksRequestEvent implements IEvent
{
    public const NAME = 'quill.editor.blocks.request';
    public function __construct(public readonly BlockRegistry $blockRegistry) {}
    public function getName(): string { return self::NAME; }
    /** @return array{blockRegistry: BlockRegistry} */
    public function bundle(): array { return ['blockRegistry' => $this->blockRegistry]; }
}
