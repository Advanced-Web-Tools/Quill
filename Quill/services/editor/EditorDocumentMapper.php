<?php

namespace Quill\services\editor;

use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\shared\ConversionInput;
use Quill\services\conversion\urpcToQuill\ConversionResult;
use Quill\services\conversion\urpcToQuill\UrpcToQuillConverter;

final class EditorDocumentMapper
{
    public function map(\stdClass $input, \stdClass $originalDocument, \Quill\models\QuillPage|\Quill\models\QuillComponent|null $owner = null): ConversionResult
    {
        $metadata = []; $components = [];
        $transport = ConversionInput::copy($input);
        foreach (ConversionInput::orderedList($transport->sections ?? null, 'document/sections') as $section) {
            ConversionInput::object($section, 'section');
            foreach (ConversionInput::orderedList($section->blocks ?? null, 'section/blocks') as $block) $this->extractMetadata($block, $metadata, $components);
        }
        // Map Quill settings and dependency assets separately from the URPC block transport.
        unset($transport->settings, $transport->assets);
        $result = (new UrpcToQuillConverter())->convertJson(json_encode($transport, JSON_THROW_ON_ERROR));
        $blocks = $result->blocks;
        foreach ($blocks as $localId => $block) {
            if (isset($components[$localId - 1])) { $blocks[$localId] = $components[$localId - 1]; continue; }
            $source = $metadata[$localId - 1] ?? new \stdClass();
            foreach (get_object_vars($source) as $name => $value) $block->{$name} = $value;
            if (isset($source->styles) && isset($block->attributes->class) && is_string($block->attributes->class)) {
                // Converter-generated preview scopes are recreated from styles on the next load.
                $classes = preg_replace('/\bquill-scope-[a-f0-9]{64}\b/', '', $block->attributes->class);
                $classes = trim(preg_replace('/\s+/', ' ', $classes));
                if ($classes === '') unset($block->attributes->class); else $block->attributes->class = $classes;
            }
        }
        $result->document->assets = $originalDocument->assets ?? (object) ['styles' => [], 'scripts' => []];
        if (property_exists($input, 'assets')) {
            $assets = ConversionInput::object($input->assets, 'document/assets');
            if (array_diff(array_keys(get_object_vars($assets)), ['styles', 'scripts'])) throw new \InvalidArgumentException('Unsupported asset category.');
            $result->document->assets = ConversionInput::copy($result->document->assets);
            foreach (['styles', 'scripts'] as $type) {
                $incoming = ConversionInput::orderedList($assets->{$type} ?? [], 'document/assets/' . $type);
                foreach ($incoming as $url) if (!is_string($url) || trim($url) === '') throw new \InvalidArgumentException('Invalid component asset URL.');
                $result->document->assets->{$type} = array_values(array_unique([...($result->document->assets->{$type} ?? []), ...$incoming]));
            }
        }
        $result->document->settings = $originalDocument->settings ?? new \stdClass();
        if (property_exists($input, 'settings')) {
            $settings = ConversionInput::object($input->settings, 'document/settings');
            \Quill\services\document\DocumentSettings::validate($settings);
            $result->document->settings = ConversionInput::copy($result->document->settings);
            foreach (['title', 'description', 'styles', 'breakpoints', 'actions', 'color_palette', 'color_palettes', 'default_color_palette', 'animations', 'animation_bindings'] as $key) {
                if (property_exists($settings, $key)) $result->document->settings->{$key} = $settings->{$key};
            }
        }
        // Reuse the actual Quill validation, including feature/style restrictions, before any write.
        (new QuillToUrpcConverter())->convert(new EditorContentSource($result->document, $blocks, $owner));
        return new ConversionResult($result->document, $blocks, $result->identities);
    }

    /** @param list<\stdClass> $metadata */
    private function extractMetadata(mixed $block, array &$metadata, array &$components, int $depth = 0): void
    {
        if ($depth >= 128) throw new \InvalidArgumentException('Maximum editor block depth exceeded.');
        $block = ConversionInput::object($block, 'block');
        $source = ConversionInput::object(ConversionInput::field($block, '_quill', new \stdClass()), 'block/_quill');
        \Quill\services\animations\AnimationLibrary::validateBindings($source->animations ?? []);
        if (isset($source->component)) {
            $component = ConversionInput::object($source->component, 'block/component');
            $id = ConversionInput::id($component->id ?? null, 'block/component/id');
            $instance = $component->instanceId ?? null;
            if (!is_string($instance) || !preg_match('/^qc-[a-zA-Z0-9_-]{1,80}$/D', $instance)) throw new \InvalidArgumentException('Invalid component instance identity.');
            $components[count($metadata)] = (object) ['schema_version' => 1, 'kind' => 'component', 'component_id' => $id,
                'editor' => (object) ['componentInstanceId' => $instance, 'externalTargets' => ConversionInput::object($component->externalTargets ?? new \stdClass(), 'component/externalTargets')]];
            if (isset($source->animations)) $components[count($metadata)]->animations = $source->animations;
            $metadata[] = new \stdClass();
            // Preview content is never accepted as a write to the shared source.
            $block->descriptor = (object) ['name' => 'div', 'props' => new \stdClass(), 'renderer' => ''];
            $block->attributes = new \stdClass(); $block->content = ''; $block->children = [];
            unset($block->_quill);
            return;
        }
        $allowed = ['definition_key', 'styles', 'bindings', 'custom_settings', 'editor', 'animations'];
        if (array_diff(array_keys(get_object_vars($source)), $allowed)) throw new \InvalidArgumentException('Unsupported editor metadata.');
        if (isset($source->editor)) {
            ConversionInput::object($source->editor, 'block/editor');
            if (property_exists($source->editor, 'label') && !is_string($source->editor->label)) throw new \InvalidArgumentException('Invalid block label.');
            if (property_exists($source->editor, 'locked') && !is_bool($source->editor->locked)) throw new \InvalidArgumentException('Invalid block lock.');
        }
        $metadata[] = $source;
        unset($block->_quill);
        foreach (ConversionInput::orderedList($block->children ?? [], 'block/children') as $child) $this->extractMetadata($child, $metadata, $components, $depth + 1);
    }
}
