<?php

namespace Quill\models;

use model\interfaces\IRelationBelongs;

/** @property QuillComponentSnapshot|null $publishedSnapshot */
class QuillComponent extends QuillModel implements IRelationBelongs
{
    public ?string $model_source = 'quill_component';

    protected function writableAttributes(): ?array
    {
        return [
            'name',
            'description',
            'component_json',
            'published_snapshot_id',
            'admin_id',
            'created_at',
            'updated_at',
            'lock_version',
            'deleted_at',
        ];
    }

    protected function attributeCasts(): array
    {
        return [
            'id' => 'int',
            'published_snapshot_id' => 'int',
            'admin_id' => 'int',
            'lock_version' => 'int',
        ];
    }

    public function belongsTo(): array
    {
        return [
            'model' => QuillComponentSnapshot::class,
            'column' => 'published_snapshot_id',
            'as' => 'publishedSnapshot',
        ];
    }

    /** @return list<QuillComponentSnapshot> */
    public function snapshots(): array
    {
        if ($this->getKey() === null) return [];
        $model = (new QuillComponentSnapshot())->useConnectionFrom($this);
        return $model->newQuery()->where(['component_id' => $this->getKey()])->getModels();
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
