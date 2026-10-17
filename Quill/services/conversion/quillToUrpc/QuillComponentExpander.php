<?php

namespace Quill\services\conversion\quillToUrpc;

use Quill\services\conversion\shared\ConversionException;
use Quill\services\conversion\shared\ConversionInput;

use URPC\api\contracts\IURPCBlock;

final class QuillComponentExpander
{
    /** @return list<IURPCBlock> */
    public function expand(
        \stdClass $reference,
        QuillContentSource $source,
        ConversionContext $context,
        string $path,
        QuillDocumentConverter $documents
    ): array {
        $componentId = ConversionInput::id($reference->component_id ?? null, $path . '/component_id');
        $componentPath = $path . '/component[' . $componentId . ']';
        // Component references have no element to which renderable fields could be applied.
        foreach (['descriptor', 'attributes', 'content', 'children', 'styles', 'bindings', 'custom_settings'] as $field) {
            if (property_exists($reference, $field)) {
                throw ConversionException::at($componentPath . '/' . $field, 'Component references cannot carry element fields.');
            }
        }
        $component = $context->component($source, $componentId, $componentPath);
        $instance = $reference->editor->componentInstanceId ?? ('qc-' . substr(hash('sha256', $path), 0, 32));
        if (!is_string($instance) || !preg_match('/^qc-[a-zA-Z0-9_-]{1,80}$/D', $instance)) throw new \InvalidArgumentException('Invalid shared component identity.');
        $settings = $context->document($component, $componentPath)->settings ?? new \stdClass();
        $aliases = ConversionInput::object($reference->editor->externalTargets ?? new \stdClass(), $componentPath . '/externalTargets');
        $outerTargets = [];
        foreach (get_object_vars($aliases) as $from => $to) {
            \Quill\services\actions\ActionDefinitions::id($from); \Quill\services\actions\ActionDefinitions::id($to);
            $outerTargets[$context->scopedTarget($from)] = $to;
        }
        $context->enterComponent($instance, isset($settings->breakpoints) ? get_object_vars($settings->breakpoints) : null);
        foreach ($outerTargets as $from => $to) $context->aliasTarget($from, $context->scopedTarget($to));
        try { $document = $documents->convert($component, $context, $componentPath); }
        finally { $context->leaveComponent(); }
        $blocks = [];
        foreach ($document->getSections() as $section) {
            foreach ($section as $block) $blocks[] = $block;
        }
        return $blocks;
    }
}
