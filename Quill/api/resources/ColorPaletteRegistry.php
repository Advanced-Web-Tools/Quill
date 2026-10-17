<?php
namespace Quill\api\resources;
final class ColorPaletteRegistry
{
    private array $entries = [];
    public function add(ColorPaletteDefinition $definition, bool $replace = false): void
    {
        if (!$replace && isset($this->entries[$definition->key])) throw new \InvalidArgumentException('Duplicate external palette: ' . $definition->key);
        $this->entries[$definition->key] = $definition;
    }
    public function get(string $key): ?ColorPaletteDefinition { return $this->entries[$key] ?? null; }
    public function remove(string $key): void { unset($this->entries[$key]); }
    /** @return list<ColorPaletteDefinition> */
    public function all(): array { return array_values($this->entries); }
}
