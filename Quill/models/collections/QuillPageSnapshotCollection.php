<?php

namespace Quill\models\collections;

use model\ModelCollection;
use Quill\models\QuillPageSnapshot;

class QuillPageSnapshotCollection extends ModelCollection
{
    public function getModel(): string
    {
        return QuillPageSnapshot::class;
    }

    protected function getTable(): string
    {
        return 'quill_page_snapshot';
    }
}
