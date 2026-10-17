<?php
namespace Quill\api\events;

use event\interfaces\IEvent;
use Quill\api\resources\ColorPaletteRegistry;

final class ExternalColorPalettesRequestEvent implements IEvent
{
    public const NAME = 'quill.external.palettes.request';
    public function __construct(public readonly ColorPaletteRegistry $colorPaletteRegistry) {}
    public function getName(): string { return self::NAME; }
    public function bundle(): array { return ['colorPaletteRegistry' => $this->colorPaletteRegistry]; }
}
