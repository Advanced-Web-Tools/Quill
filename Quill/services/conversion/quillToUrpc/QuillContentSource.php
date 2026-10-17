<?php

namespace Quill\services\conversion\quillToUrpc;

interface QuillContentSource
{
    /** Identifies the working document or exact snapshot scope, never a block UID. */
    public function getScopeKey(): string;
    public function getDocument(string $path): string;
    public function getBlock(int $blockId, string $path): string;
    public function getComponent(int $componentId, string $path): QuillContentSource;
}
