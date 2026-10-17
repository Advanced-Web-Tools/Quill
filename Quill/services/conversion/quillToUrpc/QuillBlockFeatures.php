<?php

namespace Quill\services\conversion\quillToUrpc;

use Quill\services\conversion\shared\ConversionException;
use Quill\services\conversion\shared\ConversionInput;

/** Missing integration contracts must fail explicitly rather than discard client data. */
final class QuillBlockFeatures
{
    public function validate(\stdClass $block, string $path): void
    {
        if (property_exists($block, 'definition_key') && (!is_string($block->definition_key) || trim($block->definition_key) === '')) {
            throw ConversionException::at($path . '/definition_key', 'Expected a non-empty definition key.');
        }
        $bindings = ConversionInput::object(ConversionInput::field($block, 'bindings', new \stdClass()), $path . '/bindings');
        if (get_object_vars($bindings) !== []) {
            throw ConversionException::at($path . '/bindings', 'No registered bindings resolver is available in this Quill package.');
        }
        $settings = ConversionInput::object(ConversionInput::field($block, 'custom_settings', new \stdClass()), $path . '/custom_settings');
        if (get_object_vars($settings) !== []) {
            $definition = $block->definition_key ?? '(missing definition_key)';
            throw ConversionException::at($path . '/custom_settings', "No trusted QuillSetting registry/normalization contract is available for {$definition}.");
        }
    }
}
