<?php

namespace Quill\api\editor;

final class BlockRegistry
{
    /** @var array<string, BlockDefinition> */
    private array $definitions = [];

    public function add(BlockDefinition $definition, bool $replace = false): void
    {
        if (!$replace && isset($this->definitions[$definition->key])) throw new \InvalidArgumentException('Duplicate block definition: ' . $definition->key);
        $this->definitions[$definition->key] = $definition;
    }
    public function remove(string $key): void { unset($this->definitions[$key]); }
    public function get(string $key): ?BlockDefinition { return $this->definitions[$key] ?? null; }
    /** @return list<BlockDefinition> */
    public function all(): array { return array_values($this->definitions); }
}
