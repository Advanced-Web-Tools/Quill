<?php
namespace Quill\services\rendering;
use Quill\services\conversion\quillToUrpc\{ConversionContext,QuillDocumentConverter};
use Quill\services\editor\{ExternalResources,ExternalPageOverrides};

/** Quill-only reference marker: expanded before any final URPC render. */
final class ExternalComponentReference
{
    public static function matches(\stdClass $block): bool {return ltrim($block->descriptor->renderer??'','\\')===self::class;}
    public static function expand(\stdClass $reference, ConversionContext $context, QuillDocumentConverter $documents, string $path): array
    {
        global $eventDispatcher;
        if(!$eventDispatcher instanceof \event\EventDispatcher)throw new \LogicException('External components require the framework event dispatcher.');
        $key=$reference->descriptor->props->key??null;
        if(!is_string($key))throw new \InvalidArgumentException('External component reference requires a registry key.');
        \Quill\api\editor\DefinitionValidation::key($key);
        $definition=(new ExternalResources())->components($eventDispatcher)->get($key);
        if(!$definition)return [];
        $source=(new ExternalPageOverrides($eventDispatcher))->componentSource($definition);
        $instance=$reference->editor->componentInstanceId??null;
        if(!is_string($instance)||!preg_match('/^qc-[a-zA-Z0-9_-]{1,80}$/D',$instance))throw new \InvalidArgumentException('Invalid external component instance identity.');
        $settings=$context->document($source,$path)->settings??new \stdClass();
        $aliases=\Quill\services\conversion\shared\ConversionInput::object($reference->editor->externalTargets??new \stdClass(),'component/externalTargets');
        $targets=[];
        foreach(get_object_vars($aliases) as $from=>$to) {
            \Quill\services\actions\ActionDefinitions::id($from); \Quill\services\actions\ActionDefinitions::id($to);
            $targets[$context->scopedTarget($from)]=$to;
        }
        $context->enterComponent($instance,isset($settings->breakpoints)?get_object_vars($settings->breakpoints):null);
        foreach($targets as $from=>$to)$context->aliasTarget($from,$context->scopedTarget($to));
        try {$document=$documents->convert($source,$context,$path.'/external['.$key.']');}
        finally {$context->leaveComponent();}
        $blocks=[];foreach($document->getSections() as $section)$blocks=[...$blocks,...array_values($section)];
        return $blocks;
    }
}
