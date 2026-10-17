<?php

namespace Quill\services\rendering;

use database\DatabaseManager;
use Quill\models\QuillComponent;
use Quill\models\QuillComponentSnapshot;
use Quill\services\conversion\quillToUrpc\AnimationContentSource;
use Quill\services\conversion\quillToUrpc\QuillContentSource;
use Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource;
use Quill\services\conversion\shared\ConversionException;

/** Public view: frozen page content with independently published shared components. */
final class PublishedComponentContentSource implements QuillContentSource, AnimationContentSource
{
    public function __construct(private QuillContentSource $source, private DatabaseManager $database) {}

    public function getScopeKey(): string { return 'public/' . $this->source->getScopeKey(); }
    public function getDocument(string $path): string { return $this->source->getDocument($path); }
    public function getBlock(int $blockId, string $path): string { return $this->source->getBlock($blockId, $path); }
    public function getAnimationDefinition(int $animationId): ?\stdClass
    {
        return $this->source instanceof AnimationContentSource ? $this->source->getAnimationDefinition($animationId) : null;
    }

    public function getComponent(int $componentId, string $path): QuillContentSource
    {
        // Keep the source manifest's ownership/integrity checks before selecting a newer version.
        $captured = $this->source->getComponent($componentId, $path);
        $component = (new QuillComponent())->useConnectionFrom($this->database)->newQuery()->where(['id' => $componentId])->firstModel();
        $snapshotId = $component?->getParam('published_snapshot_id');
        if ($component !== null && $component->getParam('deleted_at') === null && $snapshotId !== null) {
            $snapshot = (new QuillComponentSnapshot())->useConnectionFrom($this->database)->newQuery()->where([
                'id' => $snapshotId, 'component_id' => $componentId,
            ])->firstModel();
            if ($snapshot === null) throw ConversionException::at($path, "The published snapshot for component {$componentId} is missing or belongs to another component.");
            return new self(new SnapshotQuillContentSource($snapshot), $this->database);
        }
        // Legacy components may only have a snapshot captured when their host page was published.
        // Retain it for unpublished/deleted sources rather than exposing working drafts.
        return new self($captured, $this->database);
    }
}
