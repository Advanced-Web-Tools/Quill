<?php

namespace Quill\api\editor;

final class PropertyRegistry
{
    /** @var array<string, PropertyDefinition> */
    private array $definitions = [];

    public function add(PropertyDefinition $definition, bool $replace = false): void
    {
        if (!$replace && isset($this->definitions[$definition->key])) throw new \InvalidArgumentException('Duplicate property definition: ' . $definition->key);
        $this->definitions[$definition->key] = $definition;
    }
    public function remove(string $key): void { unset($this->definitions[$key]); }
    public function get(string $key): ?PropertyDefinition { return $this->definitions[$key] ?? null; }
    /** @return list<PropertyDefinition> */
    public function all(): array { return array_values($this->definitions); }
}
