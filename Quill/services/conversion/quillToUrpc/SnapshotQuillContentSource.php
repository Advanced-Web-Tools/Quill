<?php

namespace Quill\services\conversion\quillToUrpc;

use Quill\services\conversion\shared\ConversionException;

use Quill\models\QuillBlockSnapshot;
use Quill\models\QuillComponentSnapshot;
use Quill\models\QuillComponentSnapshotBlock;
use Quill\models\QuillComponentSnapshotComponent;
use Quill\models\QuillPageSnapshot;
use Quill\models\QuillPageSnapshotBlock;
use Quill\models\QuillPageSnapshotComponent;

final class SnapshotQuillContentSource implements QuillContentSource, \Quill\services\conversion\quillToUrpc\AnimationContentSource
{
    public function __construct(private QuillPageSnapshot|QuillComponentSnapshot $owner)
    {
        if ($owner->getKey() === null) {
            throw ConversionException::at('document', 'Snapshot owner must be a loaded snapshot.');
        }
    }

    public function getAnimationDefinition(int $animationId): ?\stdClass
    {
        return (new \Quill\services\animations\AnimationLibrary($this->owner))->definition($animationId);
    }

    public function getScopeKey(): string
    {
        return 'snapshot/' . ($this->owner instanceof QuillPageSnapshot ? 'page/' : 'component/') . $this->owner->getKey();
    }

    public function getDocument(string $path): string
    {
        if ($this->owner instanceof QuillPageSnapshot && $this->owner->getParam('source_type') === 'urpc') {
            throw ConversionException::at($path, 'Unsupported operation: a URPC source snapshot requires the separate override pipeline.');
        }
        return (string) $this->owner->getParam($this->owner instanceof QuillPageSnapshot ? 'page_json' : 'component_json');
    }

    public function getBlock(int $blockId, string $path): string
    {
        $pageScope = $this->owner instanceof QuillPageSnapshot;
        $model = ($pageScope ? new QuillPageSnapshotBlock() : new QuillComponentSnapshotBlock())
            ->useConnectionFrom($this->owner);
        $links = $model->newQuery()->where([
            $pageScope ? 'page_snapshot_id' : 'component_snapshot_id' => $this->owner->getKey(),
            'block_id' => $blockId,
        ])->getModels(2);
        if (count($links) !== 1) {
            throw ConversionException::at($path, "Missing or ambiguous manifest entry for block {$blockId} in {$this->getScopeKey()}.");
        }
        $snapshotModel = (new QuillBlockSnapshot())->useConnectionFrom($this->owner);
        $snapshot = $snapshotModel->newQuery()->where([
            'id' => $links[0]->getParam('block_snapshot_id'), 'block_id' => $blockId,
        ])->firstModel();
        if ($snapshot === null) {
            throw ConversionException::at($path, "Missing exact snapshot for block {$blockId} in {$this->getScopeKey()}.");
        }
        return (string) $snapshot->getParam('block_json');
    }

    public function getComponent(int $componentId, string $path): QuillContentSource
    {
        $pageScope = $this->owner instanceof QuillPageSnapshot;
        $model = ($pageScope ? new QuillPageSnapshotComponent() : new QuillComponentSnapshotComponent())
            ->useConnectionFrom($this->owner);
        $links = $model->newQuery()->where([
            $pageScope ? 'page_snapshot_id' : 'owner_snapshot_id' => $this->owner->getKey(),
            'component_id' => $componentId,
        ])->getModels(2);
        if (count($links) !== 1) {
            throw ConversionException::at($path, "Missing or ambiguous manifest entry for component {$componentId} in {$this->getScopeKey()}.");
        }
        $snapshotModel = (new QuillComponentSnapshot())->useConnectionFrom($this->owner);
        $snapshot = $snapshotModel->newQuery()->where([
            'id' => $links[0]->getParam($pageScope ? 'component_snapshot_id' : 'referenced_snapshot_id'),
            'component_id' => $componentId,
        ])->firstModel();
        if ($snapshot === null) {
            throw ConversionException::at($path, "Missing exact snapshot for component {$componentId} in {$this->getScopeKey()}.");
        }
        return new self($snapshot);
    }
}
