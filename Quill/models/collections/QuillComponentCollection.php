<?php

namespace Quill\models\collections;

use model\ModelCollection;
use Quill\models\QuillComponent;

class QuillComponentCollection extends ModelCollection
{
    public function getModel(): string
    {
        return QuillComponent::class;
    }

    protected function getTable(): string
    {
        return 'quill_component';
    }
}
