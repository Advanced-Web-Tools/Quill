<?php

namespace Quill\controllers;

use URPC\service\renderer\URPC;
use Quill\models\QuillPage;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\rendering\QuillPageDocumentProvider;
use URPC\api\contracts\IURPCDocument;
use URPC\service\contracts\provider\IURPCDocumentProvider;

final class PublicPageController extends URPC
{
    public ?QuillPage $page = null;
    private ?IURPCDocumentProvider $activeProvider = null;
    private bool $usesExplicitDocument = false;
    private ?IURPCDocument $preparedDocument = null;
    private ?\Quill\services\data\ConnectionRecords $connectionRecords = null;

    public function setDataConnectionRecords(\Quill\services\data\ConnectionRecords $records): self
    {
        $this->connectionRecords = $records;
        return $this;
    }

    public function index(array|string $params = []): self
    {
        $this->shared['quill.route_parameters'] = is_array($params) ? $params : [];
        return $this;
    }

    protected function prepareDocument(IURPCDocument $document): IURPCDocument
    {
        return $this->preparedDocument = (new \Quill\services\rendering\ConnectedDataRenderer($this->connectionRecords))->prepare(parent::prepareDocument($document), $this);
    }

    public function setPage(QuillPage $page, ?QuillToUrpcConverter $converter = null, bool $draft = false): void
    {
        $this->page = $page;
        $this->setSourceNamespace('Quill/page/' . ($page->getKey() ?? 'unloaded'));
        $this->setDocumentProvider(new QuillPageDocumentProvider($page, $converter ?? new QuillToUrpcConverter(), $draft));
        $this->clearDocument();
    }

    public function setDocument(IURPCDocument $document): void
    {
        parent::setDocument($document);
        $this->usesExplicitDocument = true;
    }

    public function setDocumentProvider(IURPCDocumentProvider $provider): self
    {
        parent::setDocumentProvider($provider);
        $this->activeProvider = $provider;
        return $this;
    }

    public function clearDocument(): self
    {
        parent::clearDocument();
        $this->usesExplicitDocument = false;
        return $this;
    }

    public function render(): string
    {
        // URPC retains ownership of copying, identity assignment, events, processors and escaping.
        $this->preparedDocument = null;
        $html = parent::render();
        if ($this->usesExplicitDocument || !$this->activeProvider instanceof QuillPageDocumentProvider) return $html;
        if ($this->getDocument() !== $this->preparedDocument) return $html;
        $result = $this->activeProvider->getConversionResult();
        if ($result === null) return $html;
        $styles = '';
        foreach ($result->assets['styles'] as $url) {
            $styles .= '<link rel="stylesheet" href="' . $this->escapeAssetUrl($url) . '">';
        }
        if ($result->scopedCss !== '') $styles .= '<style>' . $result->scopedCss . '</style>';
        $scripts = '';
        foreach ($result->assets['scripts'] as $url) {
            $scripts .= '<script src="' . $this->escapeAssetUrl($url) . '" defer></script>';
        }
        if ($result->actions !== []) {
            $config = json_encode(['actions' => $result->actions, 'breakpoints' => (object) $result->actionBreakpoints], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $scripts .= '<script type="application/json" data-quill-actions>' . $config . '</script>'
                . '<script src="/awt_packages/Quill/views/assets/js/quill-actions-runtime.js" defer></script>';
        }
        return $styles . $html . $scripts;
    }

    private function escapeAssetUrl(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
