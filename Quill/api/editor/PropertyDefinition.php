<?php

namespace Quill\api\editor;

final class PropertyDefinition
{
    /** @var list<PropertyElement> */
    public readonly array $elements;

    /** @param list<string|PropertyElement> $elements */
    public function __construct(
        public readonly string $key,
        public readonly string $category,
        array $elements,
        public readonly ?string $condition = null,
        public readonly ?string $categoryLabel = null,
        public readonly ?string $icon = null
    ) {
        DefinitionValidation::key($key);
        DefinitionValidation::key($category);
        DefinitionValidation::module($condition);
        if (!array_is_list($elements) || $elements === []) throw new \InvalidArgumentException('A property requires an ordered, non-empty list of elements.');
        $normalized = [];
        foreach ($elements as $element) {
            if (is_string($element)) $element = new PropertyElement($element);
            if (!$element instanceof PropertyElement) throw new \InvalidArgumentException('Property elements must be module URLs or PropertyElement instances.');
            $normalized[] = $element;
        }
        $this->elements = $normalized;
    }
}
