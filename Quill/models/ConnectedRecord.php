<?php

namespace Quill\models;

/** Dynamically sourced AWT read model. No table-specific model is required. */
final class ConnectedRecord extends \model\Model
{
    // Source tables are quoted; bypass row caching through the framework's own opt-out.
    // This also keeps data current when source writes invalidate an unquoted table key.
    use \database\trait\DoNotCache;

    public static function column(object $record, string $column): mixed { return $record->getParam($column); }
}
