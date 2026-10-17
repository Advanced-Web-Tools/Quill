<?php

namespace Quill\models;

use model\interfaces\IRelationBelongs;
use model\interfaces\IRelationWith;

/** @property QuillComponentSnapshot|null $componentSnapshot */
/** @property QuillBlockSnapshot|null $blockSnapshot */
class QuillComponentSnapshotBlock extends QuillModel implements IRelationBelongs, IRelationWith
{
    public ?string $model_source = 'quill_component_snapshot_block';

    protected function writableAttributes(): ?array
    {
        return [
            'component_snapshot_id',
            'block_id',
            'block_snapshot_id',
        ];
    }

    protected function attributeCasts(): array
    {
        return [
            'id' => 'int',
            'component_snapshot_id' => 'int',
            'block_id' => 'int',
            'block_snapshot_id' => 'int',
        ];
    }

    public function belongsTo(): array
    {
        return [
            'model' => QuillComponentSnapshot::class,
            'column' => 'component_snapshot_id',
            'as' => 'componentSnapshot',
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
