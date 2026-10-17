<?php

namespace Quill\models\collections;

use model\ModelCollection;
use Quill\models\QuillDynamicRoute;

class QuillDynamicRouteCollection extends ModelCollection
{
    public function getModel(): string
    {
        return QuillDynamicRoute::class;
    }

    protected function getTable(): string
    {
        return 'quill_dynamic_route';
    }
}
