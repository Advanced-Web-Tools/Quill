<?php

namespace Quill\services\editor;

use Quill\models\QuillComponent;
use Quill\models\QuillPage;
use Quill\services\conversion\quillToUrpc\QuillContentSource;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\quillToUrpc\UrpcJsonSerializer;
use Quill\services\conversion\quillToUrpc\WorkingQuillContentSource;
use Quill\services\conversion\shared\ConversionInput;
use URPC\api\contracts\IURPCBlock;

final class EditorDocumentLoader
{
    private array $components = [];

    public function load(QuillPage|QuillComponent $owner): array
    {
        $source = new WorkingQuillContentSource($owner);
        $result = (new QuillToUrpcConverter())->convert($source);
        $document = $this->expanded($result);
        $original = $owner->getDocument();
        foreach ($original->sections as $index => $section) {
            $offset = 0;
            $document->sections[$index]->blocks = $this->group($section->blocks, $source, $document->sections[$index]->blocks, $offset, $owner, 'section/' . $index);
        }
        $document->settings = $original->settings ?? new \stdClass();
        $document->settings->title = $owner->name;
        $document->settings->description = $owner->description ?? '';
        $document->assets = $original->assets ?? (object) ['styles' => [], 'scripts' => []];
        return ['document' => $document, 'css' => $result->scopedCss, 'assets' => $result->assets];
    }

    /** Editor transport for an already expanded conversion, without shared-instance wrappers. */
    public function expanded(\Quill\services\conversion\quillToUrpc\ConversionResult $result): \stdClass
    {
        $document = json_decode((new UrpcJsonSerializer())->serializeDocument($result->document), false, 512, JSON_THROW_ON_ERROR);
        foreach (array_values($result->document->getSections()) as $sectionIndex => $blocks) {
            foreach (array_values($blocks) as $index => $block) $this->metadata($document->sections[$sectionIndex]->blocks[$index], $block, $result->blockSources);
        }
        return $document;
    }

    private function group(array $references, QuillContentSource $source, array $nodes, int &$offset, QuillPage|QuillComponent $owner, string $path): array
    {
        $grouped = [];
        foreach ($references as $index => $id) {
            $input = ConversionInput::decode($source->getBlock($id, $path), $path);
            if ($input->kind === 'component') {
                $componentId = $input->component_id;
                if (!isset($this->components[$componentId])) {
                    $model = (new QuillComponent())->useConnectionFrom($owner);
                    $this->components[$componentId] = $model->newQuery()->where(['id' => $componentId, 'deleted_at' => null])->firstModel()
                        ?? throw new \InvalidArgumentException('This shared component is no longer available.');
                }
                $component = $this->components[$componentId];
                $nestedSource = $source->getComponent($componentId, $path);
                $nested = ConversionInput::decode($nestedSource->getDocument($path), $path);
                $children = [];
                foreach ($nested->sections as $sectionIndex => $section) {
                    $children = [...$children, ...$this->group($section->blocks, $nestedSource, $nodes, $offset, $owner, $path . '/' . $index . '/component/' . $componentId . '/' . $sectionIndex)];
                }
                $grouped[] = (object) [
                    'descriptor' => (object) ['name' => 'quill-component', 'props' => new \stdClass(), 'renderer' => ''],
                    'attributes' => new \stdClass(), 'content' => '', 'children' => $children,
                    '_quill' => (object) ['animations' => $input->animations ?? [], 'component' => (object) [
                        'id' => $componentId, 'name' => $component->name, 'version' => $component->lock_version,
                        'externalTargets' => $input->editor->externalTargets ?? new \stdClass(),
                        'instanceId' => $input->editor->componentInstanceId ?? ('qc-' . substr(hash('sha256', $source->getScopeKey() . '/' . $path . '/' . $index), 0, 32)),
                        'assets' => $nested->assets ?? (object) ['styles' => [], 'scripts' => []],
                        'breakpoints' => $nested->settings->breakpoints ?? (object) ['tablet' => 1024, 'mobile' => 767],
                    ]],
                ];
            } else {
                $node = $nodes[$offset++] ?? throw new \LogicException('Component preview does not match the converted tree.');
                $childOffset = 0;
                $node->children = $this->group($input->children ?? [], $source, $node->children, $childOffset, $owner, $path . '/' . $index);
                $grouped[] = $node;
            }
        }
        return $grouped;
    }

    private function metadata(\stdClass $node, IURPCBlock $block, array $sources): void
    {
        unset($node->attributes->{'data-quill-target'}, $node->attributes->{'data-quill-animation-trigger'}, $node->attributes->{'data-quill-animation-playing'});
        if (isset($node->attributes->class)) {
            $classes = trim(preg_replace('/(?:^|\s)quill-animation-[a-f0-9]{24}(?=\s|$)/', '', $node->attributes->class));
            if ($classes === '') unset($node->attributes->class); else $node->attributes->class = $classes;
        }
        foreach (array_keys(\Quill\services\actions\ActionDefinitions::BINDINGS) as $trigger) unset($node->attributes->{\Quill\services\actions\ActionDefinitions::attribute($trigger)});
        $source = $sources[spl_object_id($block)] ?? new \stdClass();
        $node->_quill = (object) array_intersect_key(get_object_vars($source), array_flip(['definition_key', 'styles', 'bindings', 'custom_settings', 'editor', 'animations']));
        foreach (array_values($block->getBlockChildren()) as $index => $child) $this->metadata($node->children[$index], $child, $sources);
    }
}
