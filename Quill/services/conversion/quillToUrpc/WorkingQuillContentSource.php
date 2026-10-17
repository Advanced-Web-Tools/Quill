<?php

namespace Quill\services\conversion\quillToUrpc;

use Quill\services\conversion\shared\ConversionException;

use Quill\models\QuillBlock;
use Quill\models\QuillComponent;
use Quill\models\QuillPage;

final class WorkingQuillContentSource implements QuillContentSource, \Quill\services\conversion\quillToUrpc\AnimationContentSource
{
    public function __construct(private QuillPage|QuillComponent $owner)
    {
        if ($owner->getKey() === null || $owner->getParam('deleted_at') !== null) {
            throw ConversionException::at('document', 'Working owner must be a loaded, non-deleted page or component.');
        }
    }

    public function getAnimationDefinition(int $animationId): ?\stdClass
    {
        return (new \Quill\services\animations\AnimationLibrary($this->owner))->definition($animationId);
    }

    public function getScopeKey(): string
    {
        return 'working/' . ($this->owner instanceof QuillPage ? 'page/' : 'component/') . $this->owner->getKey();
    }

    public function getDocument(string $path): string
    {
        if ($this->owner instanceof QuillPage && $this->owner->getParam('source_type') === 'urpc') {
            throw ConversionException::at($path, 'Unsupported operation: a URPC source page requires the separate override pipeline.');
        }
        return (string) $this->owner->getParam($this->owner instanceof QuillPage ? 'page_json' : 'component_json');
    }

    public function getBlock(int $blockId, string $path): string
    {
        $model = (new QuillBlock())->useConnectionFrom($this->owner);
        $block = $model->newQuery()->where(['id' => $blockId, 'deleted_at' => null])->firstModel();
        if ($block === null) {
            throw ConversionException::at($path, "Missing working block {$blockId}.");
        }
        return (string) $block->getParam('block_json');
    }

    public function getComponent(int $componentId, string $path): QuillContentSource
    {
        $model = (new QuillComponent())->useConnectionFrom($this->owner);
        $component = $model->newQuery()->where(['id' => $componentId, 'deleted_at' => null])->firstModel();
        if ($component === null) {
            throw ConversionException::at($path, "Missing working component {$componentId}.");
        }
        return new self($component);
    }
}
