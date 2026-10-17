<?php

namespace Quill\services\editor;

use Quill\services\conversion\quillToUrpc\QuillContentSource;

/** Substitutes the edited component throughout dependency validation, including indirect cycles. */
final class EditorComponentContentSource implements QuillContentSource, \Quill\services\conversion\quillToUrpc\AnimationContentSource
{
    public function __construct(private QuillContentSource $source, private QuillContentSource $edited, private int $editedId) {}
    public function getAnimationDefinition(int $animationId): ?\stdClass
    {
        return $this->source instanceof \Quill\services\conversion\quillToUrpc\AnimationContentSource ? $this->source->getAnimationDefinition($animationId) : null;
    }
    public function getScopeKey(): string { return $this->source->getScopeKey(); }
    public function getDocument(string $path): string { return $this->source->getDocument($path); }
    public function getBlock(int $blockId, string $path): string { return $this->source->getBlock($blockId, $path); }
    public function getComponent(int $componentId, string $path): QuillContentSource
    {
        return $componentId === $this->editedId ? $this->edited : new self($this->source->getComponent($componentId, $path), $this->edited, $this->editedId);
    }
}
