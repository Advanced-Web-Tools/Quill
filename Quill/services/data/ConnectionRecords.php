<?php

namespace Quill\services\data;

/** Query-provider extension seam: supply records without changing the renderer. */
interface ConnectionRecords
{
    /** @return iterable<\model\Model> */
    public function find(array $connection, array $routeParameters): iterable;
}
