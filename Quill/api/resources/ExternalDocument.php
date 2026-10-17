<?php
namespace Quill\api\resources;

use Quill\api\editor\DefinitionValidation;
use URPC\api\contracts\IURPCDocument;

/** Provider-owned source. Quill never writes back to this document. */
final class ExternalDocument
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $provider,
        public readonly IURPCDocument $document,
        public readonly string $description = '',
        public readonly ?string $url = null,
    ) {
        DefinitionValidation::key($key);
        DefinitionValidation::text($name, 'Resource name');
        DefinitionValidation::text($provider, 'Provider name');
        if ($url !== null && (!str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url))) {
            throw new \InvalidArgumentException('External resource URL must be a local absolute path.');
        }
    }
    public function summary(): array
    {
        return ['id' => $this->key, 'name' => $this->name, 'provider' => $this->provider,
            'description' => $this->description, 'url' => $this->url, 'external' => true];
    }
}
