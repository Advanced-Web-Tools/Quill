<?php

namespace Quill\services\conversion\quillToUrpc;

use Quill\services\conversion\shared\ConversionException;
use Quill\services\conversion\shared\ConversionInput;

use URPC\api\URPCDocument;
use URPC\api\contracts\IURPCDocument;

final class QuillDocumentConverter
{
    public function __construct(private QuillBlockConverter $blocks) {}

    public function convert(QuillContentSource $source, ConversionContext $context, string $path): IURPCDocument
    {
        $identity = $source->getScopeKey() . '/document';
        $context->enter($identity, $path);
        $fontsEntered = false;
        try {
            $input = $context->document($source, $path);
            ConversionInput::schema($input, $path);
            ConversionInput::object(ConversionInput::field($input, 'settings', new \stdClass()), $path . '/settings');
            $sections = ConversionInput::orderedList($input->sections ?? null, $path . '/sections');
            $context->collectAssets($input, $path);
            $context->collectActions($input);
            $context->enterFonts(['base' => (array) ($input->settings->styles ?? [])]);
            $fontsEntered = true;
            $animationSettings = $input->settings ?? new \stdClass();
            $animations = \Quill\services\animations\AnimationDefinitions::compile($animationSettings, $context->sourceNamespace . '/' . $path);
            if ($animations['css'] !== '') $context->addCss($animations['css']);
            if ($animations['click']) $context->collectAssets((object) ['assets' => (object) ['scripts' => ['/awt_packages/Quill/views/assets/js/quill-animation-runtime.js']]], $path);
            foreach ($animationSettings->animation_bindings ?? [] as $binding) {
                $section = null;
                foreach ($sections as $candidate) if ($candidate->name === $binding->target->section) $section = $candidate;
                if (!$section) throw new \InvalidArgumentException('Animation target section does not exist.');
                $references = $section->blocks;
                foreach ($binding->target->path as $depth => $index) {
                    if (!isset($references[$index])) throw new \InvalidArgumentException('Animation target no longer exists.');
                    $target = $context->block($source, $references[$index], $path);
                    $references = $target->children ?? [];
                }
            }
            $document = new URPCDocument();
            $names = [];
            foreach ($sections as $index => $section) {
                $sectionPath = $path . '/sections/' . $index;
                $section = ConversionInput::object($section, $sectionPath);
                $name = $section->name ?? null;
                if (!is_string($name) || trim($name) === '' || is_numeric($name) || in_array($name, $names, true)) {
                    throw ConversionException::at($sectionPath, 'Expected a unique non-empty, non-numeric section name.');
                }
                $names[] = $name;
                $document->addSection($name);
                $references = ConversionInput::orderedList($section->blocks ?? null, $sectionPath . '/blocks');
                foreach ($references as $position => $reference) {
                    foreach ($this->blocks->convert($reference, $source, $context, $sectionPath . '/blocks/' . $position, $this, $animations['targets'], [$position], $name) as $block) {
                        $document->addBlock($name, $block);
                    }
                }
            }
            return $document;
        } finally {
            if ($fontsEntered) $context->leaveFonts();
            $context->leave($identity);
        }
    }
}
