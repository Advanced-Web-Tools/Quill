<?php

namespace Quill\models;

use model\interfaces\IRelationBelongs;

/** @property QuillComponent|null $component */
class QuillComponentSnapshot extends QuillModel implements IRelationBelongs
{
    public ?string $model_source = 'quill_component_snapshot';

    protected function writableAttributes(): ?array
    {
        return [
            'component_id',
            'revision',
            'name',
            'description',
            'component_json',
            'admin_id',
            'created_at',
        ];
    }

    protected function attributeCasts(): array
    {
        return [
            'id' => 'int',
            'component_id' => 'int',
            'revision' => 'int',
            'admin_id' => 'int',
        ];
    }

    public function belongsTo(): array
    {
        return [
            'model' => QuillComponent::class,
            'column' => 'component_id',
            'as' => 'component',
        ];
    }

    /** @return list<QuillComponentSnapshotBlock> */
    public function blockLinks(): array
    {
        if ($this->getKey() === null) return [];
        $model = (new QuillComponentSnapshotBlock())->useConnectionFrom($this);
        return $model->newQuery()->where(['component_snapshot_id' => $this->getKey()])->getModels();
    }

    /** @return list<QuillComponentSnapshotComponent> */
    public function componentLinks(): array
    {
        if ($this->getKey() === null) return [];
        $model = (new QuillComponentSnapshotComponent())->useConnectionFrom($this);
        return $model->newQuery()->where(['owner_snapshot_id' => $this->getKey()])->getModels();
    }

    public function getDocument(): \stdClass
    {
        return $this->decodeDocument('component_json');
    }

    public function setDocument(\stdClass $document): void
    {
        $this->encodeDocument('component_json', $document);
    }
}
