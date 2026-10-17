<?php

namespace Quill\services\data;

/** Versioned, data-only filter tree. No SQL or executable expressions in editor documents. */
final class ConnectionQuery
{
    public const OPERATORS = ['eq','ne','gt','gte','lt','lte','contains','not_contains','starts_with','ends_with','in','not_in','between','is_null','not_null','is_empty','not_empty'];
    public const UNARY = ['is_null','not_null','is_empty','not_empty'];

    public static function parse(mixed $input): ?array
    {
        if ($input === null) return null;
        $query = self::object($input);
        self::keys($query, ['version','where']);
        if (($query['version'] ?? null) !== 1) self::fail('Unsupported filter version.');
        $count = 0; $values = 0;
        $where = self::group($query['where'] ?? null, 0, $count, $values);
        return ['version' => 1, 'where' => $where];
    }

    private static function group(mixed $input, int $depth, int &$count, int &$values): array
    {
        if ($depth > 4) self::fail('Use at most five levels of filter groups.');
        $group = self::object($input);
        self::keys($group, ['match','conditions']);
        if (!in_array($group['match'] ?? null, ['all','any'], true)) self::fail('Choose All or Any for the group.');
        $conditions = $group['conditions'] ?? null;
        if (!is_array($conditions) || !array_is_list($conditions)) self::fail('Conditions must be a list.');
        $result = [];
        foreach ($conditions as $condition) {
            if (++$count > 64) self::fail('Use at most 64 conditions and groups.');
            $condition = self::object($condition);
            if (array_key_exists('conditions', $condition)) {
                $result[] = self::group($condition, $depth + 1, $count, $values);
                continue;
            }
            self::keys($condition, ['column','operator','value']);
            $column = $condition['column'] ?? null; $operator = $condition['operator'] ?? null;
            if (!is_string($column) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)) self::fail('Choose a valid filter field.');
            if (!in_array($operator, self::OPERATORS, true)) self::fail('Choose a supported comparison.');
            $rule = ['column' => $column, 'operator' => $operator];
            if (!in_array($operator, self::UNARY, true)) {
                $operand = self::object($condition['value'] ?? null);
                self::keys($operand, ['source','type','value','parameter','default_value']);
                $source = $operand['source'] ?? null; $type = $operand['type'] ?? 'text';
                if (!in_array($source, ['literal','route'], true) || !in_array($type, ['text','number','boolean'], true)) self::fail('Choose a value or a page address detail, and its type.');
                if (in_array($operator, ['contains','not_contains','starts_with','ends_with'], true) && $type !== 'text') self::fail('Text comparisons need a text value.');
                $value = ['source' => $source, 'type' => $type];
                if ($source === 'route') {
                    $parameter = $operand['parameter'] ?? '';
                    if (!is_string($parameter) || !preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $parameter)) self::fail('Choose a page address detail for this condition.');
                    if (in_array($operator, ['in','not_in','between'], true)) self::fail('List and range comparisons use entered values.');
                    $value['parameter'] = $parameter;
                    if (array_key_exists('default_value', $operand)) $value['default_value'] = self::typed($operand['default_value'], $type);
                    $values++;
                } else {
                    $raw = $operand['value'] ?? null;
                    if (in_array($operator, ['in','not_in','between'], true)) {
                        if (!is_array($raw) || !array_is_list($raw) || count($raw) < 1 || count($raw) > 32 || ($operator === 'between' && count($raw) !== 2)) self::fail('Enter two range values or a list of 1–32 values.');
                        $value['value'] = array_map(static fn ($item) => self::typed($item, $type), $raw);
                        $values += count($raw);
                    } else { $value['value'] = self::typed($raw, $type); $values++; }
                }
                if ($values > 256) self::fail('Use at most 256 filter values.');
                $rule['value'] = $value;
            }
            $result[] = $rule;
        }
        return ['match' => $group['match'], 'conditions' => $result];
    }

    public static function columns(?array $query): array
    {
        $columns = [];
        $walk = function ($group) use (&$walk, &$columns) {
            foreach ($group['conditions'] as $condition) {
                if (isset($condition['conditions'])) $walk($condition);
                else $columns[] = $condition['column'];
            }
        };
        if ($query !== null) $walk($query['where']);
        return array_values(array_unique($columns));
    }

    /** Missing or invalid route values reject the whole query; they never remove a filter. */
    public static function resolve(?array $query, array $parameters): ?array
    {
        if ($query === null) return null;
        $missing = false;
        $walk = function ($group) use (&$walk, &$missing, $parameters) {
            foreach ($group['conditions'] as &$condition) {
                if (isset($condition['conditions'])) { $condition = $walk($condition); continue; }
                if (!isset($condition['value'])) continue;
                $operand = $condition['value'];
                if ($operand['source'] === 'literal') $condition['value'] = $operand['value'];
                else {
                    $value = $parameters[$operand['parameter']] ?? null;
                    if ($value === null || $value === '') $value = $operand['default_value'] ?? null;
                    try { $condition['value'] = self::typed($value, $operand['type']); }
                    catch (\InvalidArgumentException) { $missing = true; }
                }
            }
            unset($condition);
            $group['conditions'] = array_values(array_filter($group['conditions'], static fn ($condition) => !isset($condition['conditions']) || $condition['conditions'] !== []));
            return $group;
        };
        $where = $walk($query['where']);
        return $missing ? null : $where;
    }

    private static function typed(mixed $value, string $type): string|int|float|bool
    {
        if ($type === 'text' && is_scalar($value)) return (string) $value;
        if ($type === 'number' && (is_int($value) || is_float($value) || (is_string($value) && trim($value) !== '' && is_numeric($value)))) {
            $number = $value + 0;
            if (is_finite((float) $number)) return $number;
        }
        if ($type === 'boolean') {
            if (is_bool($value)) return $value;
            if (in_array($value, [1,'1','true'], true)) return true;
            if (in_array($value, [0,'0','false'], true)) return false;
        }
        self::fail('Enter a valid ' . $type . ' value for the condition.');
    }

    private static function object(mixed $value): array
    {
        if ($value instanceof \stdClass) return get_object_vars($value);
        if (is_array($value) && !array_is_list($value)) return $value;
        self::fail('Expected a filter object.');
    }

    private static function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) self::fail('Unsupported filter option.');
    }

    private static function fail(string $message): never { throw new \InvalidArgumentException('Connect data filters: ' . $message); }
}
