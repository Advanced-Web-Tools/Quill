<?php

namespace Quill\services\editor;

use Quill\services\conversion\quillToUrpc\QuillContentSource;

final class EditorContentSource implements QuillContentSource, \Quill\services\conversion\quillToUrpc\AnimationContentSource
{
    /** @param array<int, \stdClass> $blocks */
    public function __construct(private \stdClass $document, private array $blocks, private \Quill\models\QuillPage|\Quill\models\QuillComponent|null $owner = null) {}
    public function getAnimationDefinition(int $animationId): ?\stdClass
    {
        return (new \Quill\services\animations\AnimationLibrary($this->owner))->definition($animationId);
    }

    public function getScopeKey(): string { return 'editor/validation'; }
    public function getDocument(string $path): string { return json_encode($this->document, JSON_THROW_ON_ERROR); }
    public function getBlock(int $blockId, string $path): string
    {
        return json_encode($this->blocks[$blockId] ?? throw new \InvalidArgumentException("{$path}: Missing block {$blockId}."), JSON_THROW_ON_ERROR);
    }
    public function getComponent(int $componentId, string $path): QuillContentSource
    {
        if ($this->owner instanceof \Quill\models\QuillComponent && $this->owner->getKey() === $componentId) return $this;
        $model = new \Quill\models\QuillComponent();
        if ($this->owner) $model->useConnectionFrom($this->owner);
        $component = $model->newQuery()->where(['id' => $componentId, 'deleted_at' => null])->firstModel()
            ?? throw new \InvalidArgumentException("{$path}: Shared component is missing or deleted.");
        $source = new \Quill\services\conversion\quillToUrpc\WorkingQuillContentSource($component);
        return $this->owner instanceof \Quill\models\QuillComponent
            ? new EditorComponentContentSource($source, $this, $this->owner->getKey()) : $source;
    }
}
