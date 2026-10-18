<?php
namespace Quill\services\editor;
use Quill\services\conversion\quillToUrpc\{QuillContentSource,AnimationContentSource};
use Quill\models\QuillPage;

/** Unique conversion scope for a provider component; no working component data is read. */
final class ExternalComponentContentSource implements QuillContentSource, AnimationContentSource
{
    public function __construct(private string $key, private \stdClass $document, private array $blocks, private ?QuillPage $owner=null) {}
    public function getScopeKey(): string { return 'external/component/'.$this->key; }
    public function getDocument(string $path): string { return json_encode($this->document,JSON_THROW_ON_ERROR); }
    public function getBlock(int $blockId,string $path): string {return json_encode($this->blocks[$blockId]??throw new \OutOfBoundsException('External component block is unavailable.'),JSON_THROW_ON_ERROR);}
    public function getComponent(int $componentId,string $path): QuillContentSource {throw new \InvalidArgumentException('Provider URPC components cannot reference Quill component database IDs.');}
    public function getAnimationDefinition(int $animationId): ?\stdClass {return (new \Quill\services\animations\AnimationLibrary($this->owner))->definition($animationId);}
}
