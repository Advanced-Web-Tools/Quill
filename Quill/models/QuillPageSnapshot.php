<?php

namespace Quill\models;

use model\interfaces\IRelationBelongs;

/** @property QuillPage|null $page */
class QuillPageSnapshot extends QuillModel implements IRelationBelongs
{
    public ?string $model_source = 'quill_page_snapshot';

    protected function writableAttributes(): ?array
    {
        return [
            'page_id',
            'revision',
            'name',
            'description',
            'source_type',
            'source_key',
            'page_json',
            'admin_id',
            'created_at',
        ];
    }

    protected function attributeCasts(): array
    {
        return [
            'id' => 'int',
            'page_id' => 'int',
            'revision' => 'int',
            'admin_id' => 'int',
        ];
    }

    public function belongsTo(): array
    {
        return [
            'model' => QuillPage::class,
            'column' => 'page_id',
            'as' => 'page',
        ];
    }

    /** @return list<QuillPageSnapshotBlock> */
    public function blockLinks(): array
    {
        if ($this->getKey() === null) return [];
        $model = (new QuillPageSnapshotBlock())->useConnectionFrom($this);
        return $model->newQuery()->where(['page_snapshot_id' => $this->getKey()])->getModels();
    }

    /** @return list<QuillPageSnapshotComponent> */
    public function componentLinks(): array
    {
        if ($this->getKey() === null) return [];
        $model = (new QuillPageSnapshotComponent())->useConnectionFrom($this);
        return $model->newQuery()->where(['page_snapshot_id' => $this->getKey()])->getModels();
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
