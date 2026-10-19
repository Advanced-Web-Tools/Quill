<?php
namespace Quill\api\resources;

use Quill\api\editor\DefinitionValidation;

/** A trusted editor module implementing an image/video library browser. */
final class MediaLibraryProvider
{
    /** @param list<string> $mediaTypes */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $module,
        public readonly array $mediaTypes = ['image', 'video'],
    ) {
        DefinitionValidation::key($key);
        DefinitionValidation::text($name, 'Media library name');
        DefinitionValidation::module($module);
        if ($key === 'url') throw new \InvalidArgumentException('The url media library key is reserved.');
        if (!array_is_list($mediaTypes) || $mediaTypes === [] || array_filter($mediaTypes, static fn($type) => !is_string($type) || !in_array($type, ['image', 'video'], true)) || count(array_unique($mediaTypes)) !== count($mediaTypes)) {
            throw new \InvalidArgumentException('Media libraries must support image and/or video.');
        }
    }
    public function summary(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'module' => $this->module, 'mediaTypes' => $this->mediaTypes];
    }
}
