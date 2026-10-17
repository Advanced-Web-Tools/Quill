<?php

namespace Quill\models\collections;

use model\ModelCollection;
use Quill\models\QuillPage;

class QuillPageCollection extends ModelCollection
{
    public function getModel(): string
    {
        return QuillPage::class;
    }

    protected function getTable(): string
    {
        return 'quill_page';
    }
}
