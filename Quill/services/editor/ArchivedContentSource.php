<?php
namespace Quill\services\editor;

use Quill\services\conversion\quillToUrpc\QuillContentSource;

/** Self-contained block/component graph; shared animation resources retain database references. */
final class ArchivedContentSource implements QuillContentSource, \Quill\services\conversion\quillToUrpc\AnimationContentSource
{
    public function __construct(private \stdClass $archive, private string $scope)
    {
        if (($archive->schema_version ?? null) !== 1 || !isset($archive->sources->{$scope})) throw new \InvalidArgumentException('Invalid template archive.');
    }
    public function getAnimationDefinition(int $animationId): ?\stdClass
    {
        return (new \Quill\services\animations\AnimationLibrary())->definition($animationId);
    }
    public function getScopeKey(): string { return 'archive/' . $this->scope; }
    public function getDocument(string $path): string { return json_encode($this->archive->sources->{$this->scope}->document,JSON_THROW_ON_ERROR); }
    public function getBlock(int $blockId, string $path): string
    {
        return json_encode($this->archive->sources->{$this->scope}->blocks->{(string)$blockId} ?? throw new \InvalidArgumentException('Missing archived block.'),JSON_THROW_ON_ERROR);
    }
    public function getComponent(int $componentId, string $path): QuillContentSource
    {
        $scope=$this->archive->sources->{$this->scope}->components->{(string)$componentId} ?? throw new \InvalidArgumentException('Missing archived component.');
        return new self($this->archive,$scope);
    }
}
