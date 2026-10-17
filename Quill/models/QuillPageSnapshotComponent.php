<?php

namespace Quill\models;

use model\interfaces\IRelationBelongs;
use model\interfaces\IRelationWith;

/** @property QuillPageSnapshot|null $pageSnapshot */
/** @property QuillComponentSnapshot|null $componentSnapshot */
class QuillPageSnapshotComponent extends QuillModel implements IRelationBelongs, IRelationWith
{
    public ?string $model_source = 'quill_page_snapshot_component';

    protected function writableAttributes(): ?array
    {
        return [
            'page_snapshot_id',
            'component_id',
            'component_snapshot_id',
        ];
    }

    protected function attributeCasts(): array
    {
        return [
            'id' => 'int',
            'page_snapshot_id' => 'int',
            'component_id' => 'int',
            'component_snapshot_id' => 'int',
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
            'model' => QuillComponentSnapshot::class,
            'column' => 'component_snapshot_id',
            'as' => 'componentSnapshot',
        ];
    }

    public function getComponent(): ?QuillComponent
    {
        /** @var QuillComponent|null $related */
        $related = $this->related(QuillComponent::class, 'component_id');
        return $related;
    }
}
