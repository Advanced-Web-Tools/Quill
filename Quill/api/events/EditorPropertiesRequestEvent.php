<?php

namespace Quill\api\events;

use event\interfaces\IEvent;
use Quill\api\editor\PropertyRegistry;

final class EditorPropertiesRequestEvent implements IEvent
{
    public const NAME = 'quill.editor.properties.request';
    public function __construct(public readonly PropertyRegistry $propertyRegistry) {}
    public function getName(): string { return self::NAME; }
    /** @return array{propertyRegistry: PropertyRegistry} */
    public function bundle(): array { return ['propertyRegistry' => $this->propertyRegistry]; }
}
