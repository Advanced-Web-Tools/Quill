<?php
namespace Quill\services\conversion\quillToUrpc;

/** Optional capability for shared database animation resources, including public snapshots. */
interface AnimationContentSource
{
    public function getAnimationDefinition(int $animationId): ?\stdClass;
}
