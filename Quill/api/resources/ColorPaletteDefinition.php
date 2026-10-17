<?php
namespace Quill\api\resources;

use Quill\api\editor\DefinitionValidation;
use Quill\services\document\ColorPalette;

final class ColorPaletteDefinition
{
    public readonly array $colors;
    /** @param list<\stdClass|array{id: string, name: string, value: string}> $colors */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $provider,
        array $colors,
    ) {
        DefinitionValidation::key($key);
        DefinitionValidation::text($provider, 'Provider name');
        $colors = json_decode(json_encode($colors, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        ColorPalette::validateDefinition((object) ['name' => $name, 'colors' => $colors]);
        $this->colors = $colors;
    }
    public function summary(): array
    {
        return ['id' => $this->key, 'name' => $this->name, 'provider' => $this->provider, 'colors' => $this->colors, 'scope' => 'external'];
    }
}
