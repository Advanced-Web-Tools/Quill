<?php

namespace Quill\models\collections;

use model\ModelCollection;
use Quill\models\QuillBlockSnapshot;

class QuillBlockSnapshotCollection extends ModelCollection
{
    public function getModel(): string
    {
        return QuillBlockSnapshot::class;
    }

    protected function getTable(): string
    {
        return 'quill_block_snapshot';
    }
}
