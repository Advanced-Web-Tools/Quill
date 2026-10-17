<?php

namespace Quill\models\collections;

use model\ModelCollection;
use Quill\models\QuillPageSnapshotBlock;

class QuillPageSnapshotBlockCollection extends ModelCollection
{
    public function getModel(): string
    {
        return QuillPageSnapshotBlock::class;
    }

    protected function getTable(): string
    {
        return 'quill_page_snapshot_block';
    }
}
