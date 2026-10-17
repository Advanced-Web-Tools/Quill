<?php

namespace Quill\models\collections;

use model\ModelCollection;
use Quill\models\QuillComponentSnapshotBlock;

class QuillComponentSnapshotBlockCollection extends ModelCollection
{
    public function getModel(): string
    {
        return QuillComponentSnapshotBlock::class;
    }

    protected function getTable(): string
    {
        return 'quill_component_snapshot_block';
    }
}
