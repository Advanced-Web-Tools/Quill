<?php

namespace Quill\models\collections;

use model\ModelCollection;
use Quill\models\ConnectedRecord;

final class ConnectedRecordCollection extends ModelCollection
{
    // Match ConnectedRecord's fresh-read policy for dynamically quoted source tables.
    use \database\trait\DoNotCache;

    public function __construct(string $table, private string $sortColumn, private string $direction = 'asc', private ?int $show = 5, private ?array $filters = null)
    {
        // ModelCollection owns querying, hydration and iteration. Bound the SELECT before loading.
        parent::__construct('`' . $table . '`');
        $this->obCollection->setPropertyAccessor(ConnectedRecord::class . '::column')->setKey($sortColumn)->setOrder($direction)->sortByKey();
    }

    public function getModel(): string { return ConnectedRecord::class; }

    public function select(array $columns = ['*']): \database\DatabaseManager
    {
        if ($this->filters !== null) {
            $table = $this->queryTable();
            $this->useConnectionFrom(new ConnectedRecord(builder: new \Quill\services\data\ConnectionQueryBuilder($this->filters)));
            $this->table($table);
        }
        return parent::select($columns);
    }

    public function get(?int $limit = null, ?int $offset = null): array
    {
        $this->orderBy('`' . $this->sortColumn . '`', $this->direction);
        return parent::get($limit ?? $this->show, $offset);
    }
}
