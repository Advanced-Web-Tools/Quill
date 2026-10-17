<?php

namespace Quill\models;

use model\interfaces\IRelationBelongs;

/** @property QuillPageSnapshot|null $publishedSnapshot */
class QuillPage extends QuillModel implements IRelationBelongs
{
    public ?string $model_source = 'quill_page';

    protected function writableAttributes(): ?array
    {
        return [
            'name',
            'description',
            'status',
            'source_type',
            'source_key',
            'page_json',
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
            'model' => QuillPageSnapshot::class,
            'column' => 'published_snapshot_id',
            'as' => 'publishedSnapshot',
        ];
    }

    /** @return list<QuillPageSnapshot> */
    public function snapshots(): array
    {
        if ($this->getKey() === null) return [];
        $model = (new QuillPageSnapshot())->useConnectionFrom($this);
        return $model->newQuery()->where(['page_id' => $this->getKey()])->getModels();
    }

    /** @return list<QuillDynamicRoute> */
    public function routes(): array
    {
        if ($this->getKey() === null) return [];
        $model = (new QuillDynamicRoute())->useConnectionFrom($this);
        return $model->newQuery()->where(['page_id' => $this->getKey()])->getModels();
    }

    public function getDocument(): \stdClass
    {
        return $this->decodeDocument('page_json');
    }

    public function setDocument(\stdClass $document): void
    {
        $this->encodeDocument('page_json', $document);
    }
}
