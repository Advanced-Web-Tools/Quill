<?php

namespace Quill\models\collections;

use model\ModelCollection;
use Quill\models\QuillPageSnapshotComponent;

class QuillPageSnapshotComponentCollection extends ModelCollection
{
    public function getModel(): string
    {
        return QuillPageSnapshotComponent::class;
    }

    protected function getTable(): string
    {
        return 'quill_page_snapshot_component';
    }
}
