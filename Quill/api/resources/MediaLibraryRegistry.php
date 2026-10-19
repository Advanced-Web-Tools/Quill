<?php
namespace Quill\api\resources;

final class MediaLibraryRegistry
{
    private array $entries = [];
    public function add(MediaLibraryProvider $provider, bool $replace = false): void
    {
        if (!$replace && isset($this->entries[$provider->key])) throw new \InvalidArgumentException('Duplicate media library: ' . $provider->key);
        $this->entries[$provider->key] = $provider;
    }
    public function get(string $key): ?MediaLibraryProvider { return $this->entries[$key] ?? null; }
    public function remove(string $key): void { unset($this->entries[$key]); }
    /** @return list<MediaLibraryProvider> */
    public function all(): array { return array_values($this->entries); }
}
