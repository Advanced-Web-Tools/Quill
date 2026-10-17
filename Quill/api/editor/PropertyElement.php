<?php

namespace Quill\api\editor;

final class PropertyElement
{
    public function __construct(public readonly string $definition, public readonly ?string $condition = null)
    {
        DefinitionValidation::module($definition);
        DefinitionValidation::module($condition);
    }
}
