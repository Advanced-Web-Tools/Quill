<?php

namespace Quill\services\data;

use database\DatabaseManager;

/** Framework-registered tables and columns only; never returns row data. */
final class DataSourceCatalog
{
    private ?array $sources = null;
    public function __construct(private ?DatabaseManager $database = null) {}

    public function all(): array
    {
        if ($this->sources !== null) return $this->sources;
        $database = $this->database ?? new DatabaseManager();
        $sources = [];
        foreach ($database->getTables()->tables as $row) {
            $name = $row['name'] ?? null;
            $column = $row['column_name'] ?? null;
            if (!is_string($name) || !is_string($column)) continue;
            $sources[$name] ??= ['name' => $name, 'columns' => []];
            if (!in_array($column, $sources[$name]['columns'], true)) $sources[$name]['columns'][] = $column;
        }
        ksort($sources, SORT_NATURAL | SORT_FLAG_CASE);
        return $this->sources = array_values($sources);
    }

    public function columns(string $table): array
    {
        foreach ($this->all() as $source) if ($source['name'] === $table) return $source['columns'];
        return [];
    }
}
