<?php
namespace Quill\api\resources;

/** Request-scoped registry; use provider-qualified keys, for example Shop:catalog. */
abstract class DocumentRegistry
{
    private array $entries = [];
    public function add(ExternalDocument $definition, bool $replace = false): void
    {
        if (!$replace && isset($this->entries[$definition->key])) throw new \InvalidArgumentException('Duplicate external resource: ' . $definition->key);
        $this->entries[$definition->key] = $definition;
    }
    public function get(string $key): ?ExternalDocument { return $this->entries[$key] ?? null; }
    public function remove(string $key): void { unset($this->entries[$key]); }
    /** @return list<ExternalDocument> */
    public function all(): array { return array_values($this->entries); }
}
