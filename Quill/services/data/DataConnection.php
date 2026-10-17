<?php

namespace Quill\services\data;

/** Descriptor contract, independent of query execution and property UI. */
final class DataConnection
{
    public const RENDERER = 'Quill\\services\\rendering\\ConnectedDataRenderer';
    public const VOID_TAGS = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];

    public static function parse(array $props): array
    {
        $source = $props['source'] ?? '';
        $select = $props['select'] ?? '';
        $bind = $props['bind'] ?? '';
        $mode = $props['mode'] ?? ($bind !== '' ? 'route' : 'single');
        foreach (['source' => $source, 'select' => $select] as $key => $value) {
            if (!is_string($value) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value)) throw new \InvalidArgumentException("Connect data: choose a valid {$key}.");
        }
        if (!in_array($mode, ['single', 'route', 'collection'], true)) throw new \InvalidArgumentException('Connect data: choose one way to find records.');
        if (!is_string($bind) || ($bind !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $bind))) throw new \InvalidArgumentException('Connect data: choose a route parameter.');
        $value = $props['default_value'] ?? '';
        if (!is_scalar($value) && $value !== null) throw new \InvalidArgumentException('Connect data: the matching value must be a single value.');
        $show = $props['show'] ?? 5;
        if ($show !== 'all' && (!is_int($show) || $show < 1)) throw new \InvalidArgumentException('Connect data: Show must be a positive number or all.');
        $sort = $props['sort'] ?? 'asc';
        $sortBy = $props['sort_by'] ?? $select;
        if (!in_array($sort, ['asc', 'desc'], true) || !is_string($sortBy) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $sortBy)) throw new \InvalidArgumentException('Connect data: choose a valid sort order and column.');
        $set = self::object($props['set'] ?? []);
        $attributes = $set['attributes'] ?? [];
        if (!is_array($attributes) || !array_is_list($attributes)) throw new \InvalidArgumentException('Connect data: attributes must be a list.');
        $mapped = [];
        foreach ($attributes as $attribute) {
            $attribute = self::object($attribute);
            $name = $attribute['name'] ?? '';
            $column = $attribute['value'] ?? '';
            if (!is_string($name) || !self::attributeAllowed($name)) throw new \InvalidArgumentException('Connect data: choose a safe HTML attribute name.');
            if (!is_string($column) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)) throw new \InvalidArgumentException('Connect data: choose a column for the attribute.');
            $mapped[] = ['name' => $name, 'value' => $column];
        }
        $contentSettings = self::object($set['content'] ?? []);
        $content = $contentSettings['content'] ?? '';
        $additional = $contentSettings['additional'] ?? [];
        if (!is_array($additional) || !array_is_list($additional)) throw new \InvalidArgumentException('Connect data: additional content fields must be a list.');
        foreach ($additional as $column) if (!is_string($column) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column)) throw new \InvalidArgumentException('Connect data: choose valid additional content columns.');
        $before = $contentSettings['before'] ?? '';
        $after = $contentSettings['after'] ?? '';
        if (!is_string($before) || !is_string($after)) throw new \InvalidArgumentException('Connect data: Before and After must be text.');
        if (!is_string($content) || ($content !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $content))) throw new \InvalidArgumentException('Connect data: choose a content column.');
        return ['source' => $source, 'select' => $select, 'default_value' => $value, 'bind' => $bind, 'mode' => $mode, 'query' => ConnectionQuery::parse($props['query'] ?? null),
            'show' => $show, 'sort' => $sort, 'sort_by' => $sortBy, 'set' => ['attributes' => $mapped, 'content' => ['content' => $content, 'additional' => $additional, 'before' => $before, 'after' => $after]]];
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
