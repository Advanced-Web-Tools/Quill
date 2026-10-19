<?php

namespace Quill\services\data;

use Quill\models\ConnectedRecord;
use Quill\models\collections\ConnectedRecordCollection;

final class ModelConnectionRecords implements ConnectionRecords
{
    public function __construct(private ?DataSourceCatalog $catalog = null) {}

    public function find(array $connection, array $routeParameters): iterable
    {
        if ($connection['inherit'] ?? false) return [];
        $columns = ($this->catalog ??= new DataSourceCatalog())->columns($connection['source']);
        $needed = [$connection['select'], $connection['set']['content']['content'], ...($connection['set']['content']['additional'] ?? []), ...array_column($connection['set']['attributes'], 'value')];
        foreach ($connection['set']['attributes'] as $mapping) {
            $needed = [...$needed, ...($mapping['additional'] ?? []), ...array_values($mapping['route']['parameters'] ?? [])];
        }
        if ($connection['mode'] === 'collection') $needed[] = $connection['sort_by'];
        $query = $connection['query'] ?? null;
        $needed = [...$needed, ...ConnectionQuery::columns($query)];
        foreach (array_filter($needed, static fn ($column) => $column !== '') as $column) {
            if (!in_array($column, $columns, true)) return [];
        }
        $filters = ConnectionQuery::resolve($query, $routeParameters);
        if ($query !== null && $filters === null) return [];
        if ($connection['mode'] === 'collection') {
            return new ConnectedRecordCollection($connection['source'], $connection['sort_by'], $connection['sort'], $connection['show'] === 'all' ? null : $connection['show'], $filters);
        }
        $value = $connection['default_value'];
        if ($connection['mode'] === 'route') {
            // Route parameters alone: no query string, POST body or globals as an implicit substitute.
            $bound = $routeParameters[$connection['bind']] ?? null;
            if (is_scalar($bound) && (string) $bound !== '') $value = $bound;
        }
        if ($value === null || $value === '') return [];
        $model = new ConnectedRecord(builder: new ConnectionQueryBuilder($filters));
        $model->model_source = '`' . $connection['source'] . '`';
        $record = $model->newQuery()->where(['`' . $connection['select'] . '`' => $value])->firstModel();
        return $record === null ? [] : [$record];
    }
}
