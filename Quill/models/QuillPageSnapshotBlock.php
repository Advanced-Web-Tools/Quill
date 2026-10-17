<?php

namespace Quill\models;

use model\interfaces\IRelationBelongs;
use model\interfaces\IRelationWith;

/** @property QuillPageSnapshot|null $pageSnapshot */
/** @property QuillBlockSnapshot|null $blockSnapshot */
class QuillPageSnapshotBlock extends QuillModel implements IRelationBelongs, IRelationWith
{
    public ?string $model_source = 'quill_page_snapshot_block';

    protected function writableAttributes(): ?array
    {
        return [
            'page_snapshot_id',
            'block_id',
            'block_snapshot_id',
        ];
    }

    protected function attributeCasts(): array
    {
        return [
            'id' => 'int',
            'page_snapshot_id' => 'int',
            'block_id' => 'int',
            'block_snapshot_id' => 'int',
        ];
    }

    public function belongsTo(): array
    {
        return [
            'model' => QuillPageSnapshot::class,
            'column' => 'page_snapshot_id',
            'as' => 'pageSnapshot',
        ];
    }

    public function with(): array
    {
        return [
            'model' => QuillBlockSnapshot::class,
            'column' => 'block_snapshot_id',
            'as' => 'blockSnapshot',
        ];
    }

    public function getBlock(): ?QuillBlock
    {
        /** @var QuillBlock|null $related */
        $related = $this->related(QuillBlock::class, 'block_id');
        return $related;
    }
}
