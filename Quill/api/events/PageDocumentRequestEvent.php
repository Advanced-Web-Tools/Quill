<?php
namespace Quill\api\events;

use event\interfaces\IEvent;
use Quill\models\QuillPage;
use URPC\api\contracts\IURPCDocument;

/** Runtime-only replacement. The page's saved document and history are untouched. */
final class PageDocumentRequestEvent implements IEvent
{
    public const NAME = 'quill.page.document.request';
    public function __construct(
        public readonly QuillPage $page,
        private IURPCDocument $document,
        public readonly bool $draft,
    ) { $this->initialDocument = $document; }
    private readonly IURPCDocument $initialDocument;
    public function getName(): string { return self::NAME; }
    public function getDocument(): IURPCDocument { return $this->document; }
    public function setDocument(IURPCDocument $document, bool $retainPageAssets = false): void
    {
        $this->retainPageAssets = $retainPageAssets || $document === $this->initialDocument;
        $this->document = $document; $this->replaced = true;
    }
    private bool $replaced = false;
    private bool $retainPageAssets = false;
    public function isReplaced(): bool { return $this->replaced; }
    public function retainsPageAssets(): bool { return $this->retainPageAssets; }
    public function bundle(): array { return ['page' => $this->page, 'document' => $this->document, 'draft' => $this->draft]; }
}
