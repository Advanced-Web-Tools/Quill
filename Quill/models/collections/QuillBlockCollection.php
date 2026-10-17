<?php

namespace Quill\models\collections;

use model\ModelCollection;
use Quill\models\QuillBlock;

class QuillBlockCollection extends ModelCollection
{
    public function getModel(): string
    {
        return QuillBlock::class;
    }

    protected function getTable(): string
    {
        return 'quill_block';
    }
}
