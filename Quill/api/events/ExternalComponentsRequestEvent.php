<?php
namespace Quill\api\events;

use event\interfaces\IEvent;
use Quill\api\resources\ComponentRegistry;

final class ExternalComponentsRequestEvent implements IEvent
{
    public const NAME = 'quill.external.components.request';
    public function __construct(public readonly ComponentRegistry $componentRegistry) {}
    public function getName(): string { return self::NAME; }
    public function bundle(): array { return ['componentRegistry' => $this->componentRegistry]; }
}
