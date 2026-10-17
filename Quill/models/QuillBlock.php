<?php

namespace Quill\models;



class QuillBlock extends QuillModel
{
    public ?string $model_source = 'quill_block';

    protected function writableAttributes(): ?array
    {
        return [
            'urpc_uid',
            'block_json',
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
            'admin_id' => 'int',
            'lock_version' => 'int',
        ];
    }

    /** @return list<QuillBlockSnapshot> */
    public function snapshots(): array
    {
        if ($this->getKey() === null) return [];
        $model = (new QuillBlockSnapshot())->useConnectionFrom($this);
        return $model->newQuery()->where(['block_id' => $this->getKey()])->getModels();
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
