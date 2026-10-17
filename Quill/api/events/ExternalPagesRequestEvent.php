<?php
namespace Quill\api\events;

use event\interfaces\IEvent;
use Quill\api\resources\PageRegistry;

final class ExternalPagesRequestEvent implements IEvent
{
    public const NAME = 'quill.external.pages.request';
    public function __construct(public readonly PageRegistry $pageRegistry) {}
    public function getName(): string { return self::NAME; }
    public function bundle(): array { return ['pageRegistry' => $this->pageRegistry]; }
}
