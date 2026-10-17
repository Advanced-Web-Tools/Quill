<?php

namespace Quill\services\document;

use Quill\services\conversion\shared\ConversionInput;
use Quill\services\conversion\quillToUrpc\QuillStyleConverter;

final class DocumentSettings
{
    public static function validate(\stdClass $settings): void
    {
        foreach (['title' => 255, 'description' => 65535] as $key => $limit) {
            if (!property_exists($settings, $key)) continue;
            if (!is_string($settings->{$key}) || strlen($settings->{$key}) > $limit
                || ($key === 'title' && trim($settings->{$key}) === '')) {
                throw new \InvalidArgumentException('Invalid page ' . $key . '.');
            }
        }
        if (property_exists($settings, 'breakpoints')) {
            $breakpoints = ConversionInput::object($settings->breakpoints, 'document/settings/breakpoints');
            new QuillStyleConverter(get_object_vars($breakpoints));
        }
        if (property_exists($settings, 'animations') || property_exists($settings, 'animation_bindings')) \Quill\services\animations\AnimationDefinitions::validate($settings->animations ?? new \stdClass(), $settings->animation_bindings ?? []);
        if (property_exists($settings, 'actions')) \Quill\services\actions\ActionDefinitions::catalog($settings->actions);
        if (property_exists($settings, 'color_palette')) ColorPalette::validate($settings->color_palette);
        ColorPalette::validateCollections($settings);
        if (!property_exists($settings, 'styles')) return;
        $styles = ConversionInput::object($settings->styles, 'document/settings/styles');
        foreach (get_object_vars($styles) as $property => $value) {
            if (!preg_match('/^(?:margin|padding)(?:-(?:top|right|bottom|left))?$|^font-(?:family|weight|style)$|^background(?:-(?:color|image|size|position|position-x|position-y|repeat))?$/D', $property)) {
                throw new \InvalidArgumentException('Unsupported page style: ' . $property);
            }
        }
        (new QuillStyleConverter())->declarations($styles, 'document/settings/styles');
    }
}
