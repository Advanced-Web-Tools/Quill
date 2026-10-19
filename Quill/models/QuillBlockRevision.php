<?php
namespace Quill\models;

/** Owner-scoped history of a block and its descendant tree. */
final class QuillBlockRevision extends QuillModel
{
    public ?string $model_source = 'quill_block_revision';
    protected function writableAttributes(): ?array
    {
        return ['page_id', 'component_id', 'block_uid', 'revision', 'block_json', 'admin_id', 'created_at'];
    }
    protected function attributeCasts(): array
    {
        return ['id' => 'int', 'page_id' => 'int', 'component_id' => 'int', 'revision' => 'int', 'admin_id' => 'int'];
    }
    public function getDocument(): \stdClass { return $this->decodeDocument('block_json'); }
    public function setDocument(\stdClass $document): void { $this->encodeDocument('block_json', $document); }
}
