<?php

namespace Quill\models\collections;

use model\ModelCollection;
use Quill\models\QuillComponentSnapshotComponent;

class QuillComponentSnapshotComponentCollection extends ModelCollection
{
    public function getModel(): string
    {
        return QuillComponentSnapshotComponent::class;
    }

    protected function getTable(): string
    {
        return 'quill_component_snapshot_component';
    }
}
