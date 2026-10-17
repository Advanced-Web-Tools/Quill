<?php

namespace Quill\models;

use model\interfaces\IRelationBelongs;

/** @property QuillPage|null $page */
class QuillDynamicRoute extends QuillModel implements IRelationBelongs
{
    public ?string $model_source = 'quill_dynamic_route';

    protected function writableAttributes(): ?array
    {
        return [
            'path',
            'page_id',
            'admin_id',
            'created_at',
            'updated_at',
        ];
    }

    protected function attributeCasts(): array
    {
        return [
            'id' => 'int',
            'page_id' => 'int',
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
}
