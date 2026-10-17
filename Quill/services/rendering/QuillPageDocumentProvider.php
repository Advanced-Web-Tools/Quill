<?php

namespace Quill\services\rendering;

use context\Context;
use Quill\models\QuillPage;
use Quill\models\QuillPageSnapshot;
use Quill\services\conversion\quillToUrpc\ConversionResult;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource;
use Quill\services\conversion\quillToUrpc\WorkingQuillContentSource;
use Quill\services\conversion\shared\ConversionException;
use URPC\api\contracts\IURPCDocument;
use URPC\api\URPCDocument;
use URPC\service\contracts\provider\IURPCDocumentProvider;
use Quill\api\events\PageDocumentRequestEvent;

final class QuillPageDocumentProvider implements IURPCDocumentProvider
{
    private ?ConversionResult $result = null;

    public function __construct(private QuillPage $page, private QuillToUrpcConverter $converter, private bool $draft = false) {}

    public function createDocument(Context $context): IURPCDocument
    {
        $this->result = null;
        $pageId = $this->page->getKey();
        $path = 'page[' . ($pageId ?? 'unloaded') . ']';
        if ($pageId === null) {
            throw ConversionException::at($path, 'The page is missing or deleted.');
        }
        // Route/controller instances can outlive the lookup that registered them.
        $page = $this->page->newQuery()->where(['id' => $pageId, 'deleted_at' => null])->firstModel();
        if ($page === null) throw ConversionException::at($path, 'The page is missing or deleted.');
        $sourceNamespace = 'Quill/page/' . $pageId;
        $snapshotId = $page->getParam('published_snapshot_id');
        if ($snapshotId !== null && !$this->draft) {
            $model = (new QuillPageSnapshot())->useConnectionFrom($page);
            $snapshot = $model->newQuery()->where(['id' => $snapshotId, 'page_id' => $pageId])->firstModel();
            if ($snapshot === null) {
                throw ConversionException::at($path . '/snapshot[' . $snapshotId . ']', 'The exact published snapshot is missing or belongs to another page.');
            }
            $this->result = $this->converter->convert(new PublishedComponentContentSource(new SnapshotQuillContentSource($snapshot), $page), $sourceNamespace);
        } elseif ($page->source_type !== 'urpc' && get_object_vars($page->getDocument()) === []) {
            // The creation endpoint stores {} for a page that has not received its first block yet.
            $this->result = new ConversionResult(new URPCDocument(), ['styles' => [], 'scripts' => []], '');
        } else {
            $this->result = $this->converter->convert(new WorkingQuillContentSource($page), $sourceNamespace);
        }
        global $eventDispatcher;
        if ($eventDispatcher instanceof \event\EventDispatcher) {
            $event = new PageDocumentRequestEvent($page, $this->result->document, $this->draft);
            $eventDispatcher->dispatch($event);
            if ($event->isReplaced()) {
                // URPC copies and assigns identities to the replacement through its usual pipeline.
                // Generated selectors and actions belong to the original tree, so never attach them.
                $previous = $this->result;
                $this->result = $event->retainsPageAssets()
                    ? new ConversionResult($event->getDocument(), $previous->assets, $previous->scopedCss, $previous->blockSources, $previous->actions, $previous->actionBreakpoints)
                    : new ConversionResult($event->getDocument(), ['styles' => [], 'scripts' => []], '');
            }
        }
        return $this->result->document;
    }

    public function getConversionResult(): ?ConversionResult { return $this->result; }
}
