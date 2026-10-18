<?php

namespace Quill\services\editor;

use Quill\models\QuillBlock;
use Quill\models\QuillBlockSnapshot;
use Quill\models\QuillComponent;
use Quill\models\QuillComponentSnapshot;
use Quill\models\QuillComponentSnapshotBlock;
use Quill\models\QuillPage;
use Quill\models\QuillPageSnapshot;
use Quill\models\QuillPageSnapshotBlock;

final class EditorDocumentSaver
{
    /** @return array{lock_version: int, mode: string, snapshot_id: ?int} */
    public function save(QuillPage|QuillComponent $owner, \stdClass $document, int $version, string $mode, int $adminId): array
    {
        if (!in_array($mode, ['draft', 'publish'], true)) throw new \InvalidArgumentException('Choose draft or publish.');
        if ($owner->lock_version !== $version) throw new \DomainException('This document changed. Reload before saving.');
        if ($owner instanceof QuillPage && $owner->source_type === 'urpc') throw new \InvalidArgumentException('Use the external UID override save pipeline with its source revision.');
        $original = $owner->getDocument();
        if (get_object_vars($original) !== []) {
            (new \Quill\services\conversion\quillToUrpc\QuillToUrpcConverter())->convert(
                new \Quill\services\conversion\quillToUrpc\WorkingQuillContentSource($owner)
            );
        }
        $mapped = (new EditorDocumentMapper())->map($document, $original, $owner);
        return $owner->transaction(function () use ($owner, $mapped, $version, $mode, $adminId): array {
            $changed = $owner->table($owner->model_source)->where([
                'id' => $owner->getKey(), 'lock_version' => $version, 'deleted_at' => null,
            ])->update(['lock_version' => $version + 1, 'updated_at' => date('Y-m-d H:i:s')]);
            if (!$changed) throw new \DomainException('This document changed. Reload before saving.');
            // New rows isolate page edits from reused working blocks and published snapshots.
            $ids = [];
            foreach ($mapped->blocks as $localId => $blockDocument) {
                $block = (new QuillBlock())->useConnectionFrom($owner);
                $block->admin_id = $adminId;
                $block->setDocument($blockDocument);
                $ids[$localId] = $block->saveModel() ?? throw new \RuntimeException('Block insert failed.');
            }
            foreach ($mapped->blocks as $localId => $blockDocument) {
                if ($blockDocument->kind === 'element') $blockDocument->children = array_map(static fn (int $reference): int => $ids[$reference], $blockDocument->children);
                $block = (new QuillBlock())->useConnectionFrom($owner);
                $block->table($block->model_source)->where(['id' => $ids[$localId]])->update([
                    'block_json' => json_encode($blockDocument, JSON_THROW_ON_ERROR),
                ]);
            }
            foreach ($mapped->document->sections as $section) $section->blocks = array_map(static fn (int $reference): int => $ids[$reference], $section->blocks);
            $json = json_encode($mapped->document, JSON_THROW_ON_ERROR);
            if (isset($mapped->document->settings->title)) $owner->name = $mapped->document->settings->title;
            if (isset($mapped->document->settings->description)) $owner->description = $mapped->document->settings->description;
            $snapshotId = $mode === 'publish' ? $this->publish($owner, $json, $mapped->blocks, $ids, $adminId) : null;
            $changes = [$owner instanceof QuillPage ? 'page_json' : 'component_json' => $json];
            if (isset($mapped->document->settings->title)) $changes['name'] = $owner->name;
            if (isset($mapped->document->settings->description)) $changes['description'] = $owner->description;
            if ($owner instanceof QuillPage) $changes['status'] = $mode === 'publish' ? 'published' : 'draft';
            if ($snapshotId !== null) $changes['published_snapshot_id'] = $snapshotId;
            $owner->table($owner->model_source)->where(['id' => $owner->getKey()])->update($changes);
            return ['lock_version' => $version + 1, 'mode' => $mode, 'snapshot_id' => $snapshotId];
        });
    }

    /** @param array<int, \stdClass> $blocks @param array<int, int> $ids */
    private function publish(QuillPage|QuillComponent $owner, string $json, array $blocks, array $ids, int $adminId, array &$componentSnapshots = []): int
    {
        $page = $owner instanceof QuillPage;
        $snapshot = ($page ? new QuillPageSnapshot() : new QuillComponentSnapshot())->useConnectionFrom($owner);
        $ownerColumn = $page ? 'page_id' : 'component_id';
        $latest = $snapshot->newQuery()->where([$ownerColumn => $owner->getKey()])->orderBy('revision', 'DESC')->firstModel();
        $snapshot->{$ownerColumn} = $owner->getKey();
        $snapshot->revision = ($latest?->revision ?? 0) + 1;
        $snapshot->name = $owner->name;
        $snapshot->description = $owner->description;
        $snapshot->admin_id = $adminId;
        if ($page) {
            $snapshot->source_type = $owner->source_type;
            $snapshot->source_key = $owner->source_key;
        }
        $snapshot->{$page ? 'page_json' : 'component_json'} = $json;
        $snapshotId = $snapshot->saveModel() ?? throw new \RuntimeException('Snapshot insert failed.');
        foreach ($blocks as $localId => $document) {
            $blockSnapshot = (new QuillBlockSnapshot())->useConnectionFrom($owner);
            $blockSnapshot->block_id = $ids[$localId];
            $blockSnapshot->revision = 1;
            $blockSnapshot->admin_id = $adminId;
            $blockSnapshot->setDocument($document);
            $blockSnapshotId = $blockSnapshot->saveModel() ?? throw new \RuntimeException('Block snapshot insert failed.');
            $link = ($page ? new QuillPageSnapshotBlock() : new QuillComponentSnapshotBlock())->useConnectionFrom($owner);
            $link->{$page ? 'page_snapshot_id' : 'component_snapshot_id'} = $snapshotId;
            $link->block_id = $ids[$localId];
            $link->block_snapshot_id = $blockSnapshotId;
            if ($link->saveModel() === null) throw new \RuntimeException('Snapshot manifest insert failed.');
        }
        if (!$page) $componentSnapshots[$owner->getKey()] = $snapshotId;
        $components = [];
        foreach ($blocks as $document) if ($document->kind === 'component') $components[$document->component_id] = true;
        foreach (array_keys($components) as $componentId) {
            $referenced = $this->captureComponent($componentId, $owner, $adminId, $componentSnapshots);
            $link = ($page ? new \Quill\models\QuillPageSnapshotComponent() : new \Quill\models\QuillComponentSnapshotComponent())->useConnectionFrom($owner);
            $link->{$page ? 'page_snapshot_id' : 'owner_snapshot_id'} = $snapshotId;
            $link->component_id = $componentId;
            $link->{$page ? 'component_snapshot_id' : 'referenced_snapshot_id'} = $referenced;
            if ($link->saveModel() === null) throw new \RuntimeException('Component snapshot manifest insert failed.');
        }
        return $snapshotId;
    }

    private function captureComponent(int $id, QuillPage|QuillComponent $owner, int $adminId, array &$snapshots): int
    {
        if (isset($snapshots[$id])) return $snapshots[$id];
        $component = (new QuillComponent())->useConnectionFrom($owner)->newQuery()->where(['id' => $id, 'deleted_at' => null])->firstModel()
            ?? throw new \InvalidArgumentException('Shared component is missing or deleted.');
        $document = $component->getDocument(); $blocks = [];
        $collect = function (array $references) use (&$collect, &$blocks, $component): void {
            foreach ($references as $reference) {
                if (isset($blocks[$reference])) continue;
                $block = (new QuillBlock())->useConnectionFrom($component)->newQuery()->where(['id' => $reference, 'deleted_at' => null])->firstModel()
                    ?? throw new \InvalidArgumentException('Shared component block is missing.');
                $blocks[$reference] = $block->getDocument();
                if ($blocks[$reference]->kind === 'element') $collect($blocks[$reference]->children ?? []);
            }
        };
        foreach ($document->sections as $section) $collect($section->blocks);
        $ids = array_combine(array_keys($blocks), array_keys($blocks));
        return $this->publish($component, json_encode($document, JSON_THROW_ON_ERROR), $blocks, $ids, $adminId, $snapshots);
    }
}
