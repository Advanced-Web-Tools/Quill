<?php

namespace Quill\models;

use model\interfaces\IRelationBelongs;
use model\interfaces\IRelationWith;

/** @property QuillComponentSnapshot|null $ownerSnapshot */
/** @property QuillComponentSnapshot|null $referencedSnapshot */
class QuillComponentSnapshotComponent extends QuillModel implements IRelationBelongs, IRelationWith
{
    public ?string $model_source = 'quill_component_snapshot_component';

    protected function writableAttributes(): ?array
    {
        return [
            'owner_snapshot_id',
            'component_id',
            'referenced_snapshot_id',
        ];
    }

    protected function attributeCasts(): array
    {
        return [
            'id' => 'int',
            'owner_snapshot_id' => 'int',
            'component_id' => 'int',
            'referenced_snapshot_id' => 'int',
        ];
    }

    public function belongsTo(): array
    {
        return [
            'model' => QuillComponentSnapshot::class,
            'column' => 'owner_snapshot_id',
            'as' => 'ownerSnapshot',
        ];
    }

    public function with(): array
    {
        return [
            'model' => QuillComponentSnapshot::class,
            'column' => 'referenced_snapshot_id',
            'as' => 'referencedSnapshot',
        ];
    }

    public function getComponent(): ?QuillComponent
    {
        /** @var QuillComponent|null $related */
        $related = $this->related(QuillComponent::class, 'component_id');
        return $related;
    }
}
