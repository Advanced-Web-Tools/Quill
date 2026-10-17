<?php
namespace Quill\services\editor;

use event\EventDispatcher;
use Quill\api\resources\{PageRegistry, ComponentRegistry, ColorPaletteRegistry, ExternalDocument};
use Quill\api\events\{ExternalPagesRequestEvent, ExternalComponentsRequestEvent, ExternalColorPalettesRequestEvent};
use Quill\services\conversion\urpcToQuill\UrpcToQuillConverter;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;

final class ExternalResources
{
    public function pages(EventDispatcher $dispatcher): PageRegistry
    {
        $registry = new PageRegistry(); $dispatcher->dispatch(new ExternalPagesRequestEvent($registry)); return $registry;
    }
    public function components(EventDispatcher $dispatcher): ComponentRegistry
    {
        $registry = new ComponentRegistry(); $dispatcher->dispatch(new ExternalComponentsRequestEvent($registry)); return $registry;
    }
    public function palettes(EventDispatcher $dispatcher): ColorPaletteRegistry
    {
        $registry = new ColorPaletteRegistry(); $dispatcher->dispatch(new ExternalColorPalettesRequestEvent($registry)); return $registry;
    }
    /** Convert without rendering processors; reuse Quill's normal validation and metadata. */
    public function document(ExternalDocument $definition, bool $component = false): \stdClass
    {
        $import = (new UrpcToQuillConverter())->convert($definition->document);
        if ($component && (count($import->document->sections) !== 1 || count($import->document->sections[0]->blocks) !== 1)) {
            throw new \InvalidArgumentException('An external component must contain exactly one root block in one section.');
        }
        $result = (new QuillToUrpcConverter())->convert(new EditorContentSource($import->document, $import->blocks));
        $document = (new EditorDocumentLoader())->expanded($result);
        $document->settings = (object) ['title' => $definition->name, 'description' => $definition->description];
        $document->assets = $import->document->assets;
        return $document;
    }
}
