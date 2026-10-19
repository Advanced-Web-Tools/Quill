<?php
namespace Quill\api\events;

use event\interfaces\IEvent;
use Quill\api\resources\MediaLibraryRegistry;

final class MediaLibrariesRequestEvent implements IEvent
{
    public const NAME = 'quill.media-libraries.request';
    public function __construct(public readonly MediaLibraryRegistry $mediaLibraryRegistry) {}
    public function getName(): string { return self::NAME; }
    public function bundle(): array { return ['mediaLibraryRegistry' => $this->mediaLibraryRegistry]; }
}
