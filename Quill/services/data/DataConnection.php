<?php

namespace Quill\services\data;

/** Descriptor contract, independent of query execution and property UI. */
final class DataConnection
{
    public const RENDERER = 'Quill\\services\\rendering\\ConnectedDataRenderer';
    public const VOID_TAGS = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];

    public static function parse(array $props): array
    {
        $inherit = $props['inherit'] ?? false;
        if (!is_bool($inherit)) throw new \InvalidArgumentException('Connect data: invalid inherited connection.');
        $source = $inherit ? '' : ($props['source'] ?? '');
        $select = $inherit ? '' : ($props['select'] ?? '');
        $bind = $inherit ? '' : ($props['bind'] ?? '');
        $mode = $inherit ? 'single' : ($props['mode'] ?? ($bind !== '' ? 'route' : 'single'));
        foreach (['source' => $source, 'select' => $select] as $key => $value) {
            if (!$inherit && (!is_string($value) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value))) throw new \InvalidArgumentException("Connect data: choose a valid {$key}.");
        }
        if (!in_array($mode, ['single', 'route', 'collection'], true)) throw new \InvalidArgumentException('Connect data: choose one way to find records.');
        if (!is_string($bind) || ($bind !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $bind))) throw new \InvalidArgumentException('Connect data: choose a route parameter.');
        $value = $props['default_value'] ?? '';
        if (!is_scalar($value) && $value !== null) throw new \InvalidArgumentException('Connect data: the matching value must be a single value.');
        $show = $props['show'] ?? 5;
        if ($show !== 'all' && (!is_int($show) || $show < 1)) throw new \InvalidArgumentException('Connect data: Show must be a positive number or all.');
        $sort = $props['sort'] ?? 'asc';
        $sortBy = $inherit ? '' : ($props['sort_by'] ?? $select);
        if (!in_array($sort, ['asc', 'desc'], true) || (!$inherit && (!is_string($sortBy) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $sortBy)))) throw new \InvalidArgumentException('Connect data: choose a valid sort order and column.');
        $set = self::object($props['set'] ?? []);
        $attributes = $set['attributes'] ?? [];
        if (!is_array($attributes) || !array_is_list($attributes)) throw new \InvalidArgumentException('Connect data: attributes must be a list.');
        $mapped = [];
        foreach ($attributes as $attribute) {
            $attribute = self::object($attribute);
            $name = $attribute['name'] ?? '';
            if (!is_string($name) || !self::attributeAllowed($name)) throw new \InvalidArgumentException('Connect data: choose a safe HTML attribute name.');
            if (isset($attribute['route'])) {
                if (strtolower($name) !== 'href') throw new \InvalidArgumentException('Connect data: route destinations require href.');
                $route = self::object($attribute['route']);
                $path = $route['path'] ?? '';
                $parameters = self::object($route['parameters'] ?? []);
                if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[?#\\\\\x00-\x20\x7f<>"\']/', $path)) throw new \InvalidArgumentException('Connect data: choose a local route path.');
                $expected = [];
                foreach (explode('/', $path) as $segment) {
                    if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_-]*)\}$/D', $segment, $match)) $expected[] = $match[1];
                    elseif (strpbrk($segment, '{}') !== false) throw new \InvalidArgumentException('Connect data: invalid route parameter.');
                }
                $expected = array_values(array_unique($expected));
                if (array_diff($expected, array_keys($parameters)) || array_diff(array_keys($parameters), $expected)) throw new \InvalidArgumentException('Connect data: map every route parameter to a database field.');
                foreach ($parameters as $column) self::column($column);
                $mapped[] = ['name' => $name, 'value' => '', 'route' => ['path' => $path, 'parameters' => $parameters]];
            } else {
                $column = $attribute['value'] ?? ''; self::column($column);
                $mapped[] = ['name' => $name, 'value' => $column, ...self::composition($attribute)];
            }
        }
        $contentSettings = self::object($set['content'] ?? []);
        $content = $contentSettings['content'] ?? '';
        if (!is_string($content) || ($content !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $content))) throw new \InvalidArgumentException('Connect data: choose a content column.');
        $composition = self::composition($contentSettings);
        return ['inherit' => $inherit, 'source' => $source, 'select' => $select, 'default_value' => $value, 'bind' => $bind, 'mode' => $mode, 'query' => ConnectionQuery::parse($props['query'] ?? null),
            'show' => $show, 'sort' => $sort, 'sort_by' => $sortBy, 'set' => ['attributes' => $mapped, 'content' => ['content' => $content, ...$composition]]];
    }

    private static function column(mixed $column): void
    {
        if (!is_string($column) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)) throw new \InvalidArgumentException('Connect data: choose a valid database field.');
    }

    private static function composition(array $settings): array
    {
        $additional = $settings['additional'] ?? [];
        if (!is_array($additional) || !array_is_list($additional)) throw new \InvalidArgumentException('Connect data: additional fields must be a list.');
        foreach ($additional as $column) self::column($column);
        $before = $settings['before'] ?? ''; $after = $settings['after'] ?? '';
        $spaces = $settings['with_spaces'] ?? true;
        if (!is_string($before) || !is_string($after)) throw new \InvalidArgumentException('Connect data: Before and After must be text.');
        if (!is_bool($spaces)) throw new \InvalidArgumentException('Connect data: With spaces must be a checkbox value.');
        return ['additional' => $additional, 'before' => $before, 'after' => $after, 'with_spaces' => $spaces];
    }

    private static function object(mixed $value): array
    {
        if ($value instanceof \stdClass) return get_object_vars($value);
        if (is_array($value)) return $value;
        throw new \InvalidArgumentException('Connect data: expected an object.');
    }

    public static function attributeAllowed(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z_:][A-Za-z0-9_.:-]*$/D', $name)
            && !preg_match('/^(?:on|data-quill-)/i', $name)
            && !in_array(strtolower($name), ['style', 'srcdoc', 'srcset'], true);
    }
}
