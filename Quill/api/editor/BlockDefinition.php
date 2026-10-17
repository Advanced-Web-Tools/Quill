<?php

namespace Quill\api\editor;

use URPC\api\contracts\IURPCBlock;
use URPC\api\contracts\IURPCDocument;

final class BlockDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $icon,
        public readonly string $description,
        public readonly IURPCDocument|IURPCBlock|string $content,
        public readonly string $category = 'Basic'
    ) {
        DefinitionValidation::key($key);
        DefinitionValidation::text($name, 'Block name');
        DefinitionValidation::text($category, 'Block category');
    }
}
