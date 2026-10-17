<?php

namespace Quill\models;

use model\interfaces\IRelationBelongs;

/** @property QuillBlock|null $block */
class QuillBlockSnapshot extends QuillModel implements IRelationBelongs
{
    public ?string $model_source = 'quill_block_snapshot';

    protected function writableAttributes(): ?array
    {
        return [
            'block_id',
            'revision',
            'urpc_uid',
            'block_json',
            'admin_id',
            'created_at',
        ];
    }

    protected function attributeCasts(): array
    {
        return [
            'id' => 'int',
            'block_id' => 'int',
            'revision' => 'int',
            'admin_id' => 'int',
        ];
    }

    public function belongsTo(): array
    {
        return [
            'model' => QuillBlock::class,
            'column' => 'block_id',
            'as' => 'block',
        ];
    }

    public function getDocument(): \stdClass
    {
        return $this->decodeDocument('block_json');
    }

    public function setDocument(\stdClass $document): void
    {
        $this->encodeDocument('block_json', $document);
    }
}
