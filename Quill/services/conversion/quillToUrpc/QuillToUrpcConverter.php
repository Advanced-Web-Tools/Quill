<?php

namespace Quill\services\conversion\quillToUrpc;

final class QuillToUrpcConverter
{

    /** @param array{tablet?: int, mobile?: int} $breakpoints Explicit maximum widths; no Quill defaults exist. */
    public function __construct(private array $breakpoints = [], private int $maxDepth = 128)
    {
        if ($maxDepth < 1) throw new \InvalidArgumentException('Maximum conversion depth must be positive.');
        new QuillStyleConverter($breakpoints);
    }

    public function convert(QuillContentSource $source, ?string $sourceNamespace = null): ConversionResult
    {
        $namespace = $sourceNamespace ?? 'Quill/' . $source->getScopeKey();
        $context = new ConversionContext($namespace, $this->maxDepth);
        $path = 'document[' . $source->getScopeKey() . ']';
        $settings = \Quill\services\conversion\shared\ConversionInput::object($context->document($source, $path)->settings ?? new \stdClass(), $path . '/settings');
        \Quill\services\document\DocumentSettings::validate($settings);
        $breakpoints = array_replace(get_object_vars($settings->breakpoints ?? new \stdClass()), $this->breakpoints);
        $documents = new QuillDocumentConverter(new QuillBlockConverter(
            new QuillComponentExpander(), new QuillStyleConverter($breakpoints), new QuillBlockFeatures()
        ));
        $document = $documents->convert($source, $context, $path);
        $pageCss = (new QuillStyleConverter())->declarations($settings->styles ?? new \stdClass(), 'document/settings/styles');
        if ($pageCss !== '') $context->addCss('body{' . $pageCss . '}');
        $context->validateActionBindings();
        return new ConversionResult($document, $context->getAssets(), $context->getCss(), $context->getBlockSources(), $context->getActions(), $breakpoints);
    }
}
