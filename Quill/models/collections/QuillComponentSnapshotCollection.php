<?php

namespace Quill\models\collections;

use model\ModelCollection;
use Quill\models\QuillComponentSnapshot;

class QuillComponentSnapshotCollection extends ModelCollection
{
    public function getModel(): string
    {
        return QuillComponentSnapshot::class;
    }

    protected function getTable(): string
    {
        return 'quill_component_snapshot';
    }
}
