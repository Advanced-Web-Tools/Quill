<?php

namespace Quill\services\data;

use database\query\QueryBuilder;
use database\query\QueryPayload;

/** Adds grouped predicates missing from AWT's builder; AWT still owns SELECT/ORDER/LIMIT/execution. */
final class ConnectionQueryBuilder extends QueryBuilder
{
    // Immutable connection configuration survives AWT's clone/reset when starting model queries.
    public function __construct(private readonly ?array $filters = null) { parent::__construct(); }

    public function buildSelect(?int $limit = null, ?int $offset = null): QueryPayload
    {
        $payload = parent::buildSelect($limit, $offset);
        if ($this->filters === null || $this->filters['conditions'] === []) return $payload;
        $bindings = [];
        $predicate = $this->group($this->filters, $bindings);
        $position = strlen($payload->sql);
        foreach ([' ORDER BY ', ' LIMIT '] as $clause) {
            $at = strpos($payload->sql, $clause);
            if ($at !== false) $position = min($position, $at);
        }
        $head = substr($payload->sql, 0, $position); $tail = substr($payload->sql, $position);
        $where = strpos($head, ' WHERE ');
        if ($where === false) $head .= ' WHERE ' . $predicate;
        else $head = substr($head, 0, $where) . ' WHERE (' . substr($head, $where + 7) . ') AND ' . $predicate;
        return new QueryPayload($head . $tail, [...$payload->bindings, ...$bindings], $payload->table, $payload->conditions);
    }

    private function group(array $group, array &$bindings): string
    {
        $parts = [];
        foreach ($group['conditions'] as $condition) $parts[] = isset($condition['conditions']) ? $this->group($condition, $bindings) : $this->condition($condition, $bindings);
        // Empty groups apply no restriction, including an empty Any group while editing.
        return $parts === [] ? '(1 = 1)' : '(' . implode($group['match'] === 'all' ? ' AND ' : ' OR ', $parts) . ')';
    }

    private function condition(array $condition, array &$bindings): string
    {
        $column = '`' . $condition['column'] . '`'; $operator = $condition['operator']; $value = $condition['value'] ?? null;
        // Use AWT's own equality / null / LIKE predicates and their parameter binding.
        if (in_array($operator, ['eq','ne','is_null','not_null','contains','not_contains','starts_with','ends_with'], true)) {
            $builder = (new QueryBuilder())->table('`quill_filter`')->select();
            $like = in_array($operator, ['contains','not_contains','starts_with','ends_with'], true);
            if ($like) {
                $escaped = str_replace(['!','%','_'], ['!!','!%','!_'], (string) $value);
                $pattern = ($operator === 'starts_with' ? '' : '%') . $escaped . ($operator === 'ends_with' ? '' : '%');
                $builder->like([$column => $pattern], $operator === 'not_contains');
            } else $builder->where([$column => in_array($operator, ['is_null','not_null'], true) ? null : $value], in_array($operator, ['ne','not_null'], true));
            $payload = $builder->buildSelect();
            $sql = substr($payload->sql, strpos($payload->sql, ' WHERE ') + 7);
            $names = [];
            foreach ($payload->bindings as $name => $bound) $names[$name] = $this->bind($bound, $bindings);
            return '(' . strtr($sql, $names) . ($like ? " ESCAPE '!'" : '') . ')';
        }
        if ($operator === 'is_empty') return '(' . $column . " IS NULL OR " . $column . " = '')";
        if ($operator === 'not_empty') return '(' . $column . " IS NOT NULL AND " . $column . " != '')";
        if (in_array($operator, ['in','not_in'], true)) {
            $names = [];
            foreach ($value as $item) $names[] = $this->bind($item, $bindings);
            return '(' . $column . ($operator === 'not_in' ? ' NOT IN (' : ' IN (') . implode(', ', $names) . '))';
        }
        if ($operator === 'between') return '(' . $column . ' BETWEEN ' . $this->bind($value[0], $bindings) . ' AND ' . $this->bind($value[1], $bindings) . ')';
        $sqlOperator = ['gt'=>'>','gte'=>'>=','lt'=>'<','lte'=>'<='][$operator] ?? throw new \InvalidArgumentException('Unsupported filter comparison.');
        return '(' . $column . ' ' . $sqlOperator . ' ' . $this->bind($value, $bindings) . ')';
    }

    private function bind(mixed $value, array &$bindings): string
    {
        $name = ':quill_filter_' . count($bindings); $bindings[$name] = $value; return $name;
    }
}
