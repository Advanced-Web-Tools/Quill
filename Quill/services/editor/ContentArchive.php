<?php
namespace Quill\services\editor;

use Quill\models\{QuillBlock,QuillComponent,QuillPage};
use Quill\services\conversion\quillToUrpc\{QuillContentSource,QuillToUrpcConverter};
use Quill\services\conversion\shared\ConversionInput;

final class ContentArchive
{
    public function capture(QuillContentSource $source): \stdClass
    {
        (new QuillToUrpcConverter())->convert($source);
        $sources=new \stdClass();
        $collect=function(QuillContentSource $current) use (&$collect,$sources): string {
            $key=$current->getScopeKey();
            if (isset($sources->{$key})) return $key;
            $entry=(object)['document'=>ConversionInput::decode($current->getDocument('archive'),'archive'),'blocks'=>new \stdClass(),'components'=>new \stdClass()];
            $sources->{$key}=$entry;
            $blocks=function(array $ids) use (&$blocks,&$collect,$entry,$current): void {
                foreach($ids as $id) {
                    if (isset($entry->blocks->{(string)$id})) continue;
                    $block=ConversionInput::decode($current->getBlock($id,'archive/block'),'archive/block');
                    $entry->blocks->{(string)$id}=$block;
                    if ($block->kind==='element') $blocks($block->children??[]);
                    else $entry->components->{(string)$block->component_id}=$collect($current->getComponent($block->component_id,'archive/component'));
                }
            };
            foreach($entry->document->sections as $section) $blocks($section->blocks);
            return $key;
        };
        $archive=(object)['schema_version'=>1,'root'=>$collect($source),'sources'=>$sources];
        (new QuillToUrpcConverter())->convert(new ArchivedContentSource($archive,$archive->root));
        return $archive;
    }

    /** Caller owns the transaction and updates the target row after this copy. */
    public function materialize(\stdClass $archive, QuillPage $target, int $adminId): \stdClass
    {
        $source=new ArchivedContentSource($archive,$archive->root);
        (new QuillToUrpcConverter())->convert($source);
        $components=[];
        $copy=function(QuillContentSource $current, QuillPage|QuillComponent $owner) use (&$copy,&$components,$target,$adminId): \stdClass {
            $document=ConversionInput::decode($current->getDocument('restore'),'restore');
            $ids=[];
            $blockCopy=function(int $id) use (&$blockCopy,&$ids,&$copy,&$components,$current,$target,$adminId): int {
                if(isset($ids[$id]))return $ids[$id];
                $document=ConversionInput::decode($current->getBlock($id,'restore/block'),'restore/block');
                if($document->kind==='element') $document->children=array_map($blockCopy,$document->children??[]);
                else {
                    $nested=$current->getComponent($document->component_id,'restore/component');$key=$nested->getScopeKey();
                    if(!isset($components[$key])) {
                        $component=(new QuillComponent())->useConnectionFrom($target);
                        $nestedDocument=ConversionInput::decode($nested->getDocument('restore/component'),'restore/component');
                        $component->name=$nestedDocument->settings->title??'Copied component';$component->description=$nestedDocument->settings->description??null;$component->admin_id=$adminId;$component->setDocument(new \stdClass());
                        $components[$key]=$component->saveModel()??throw new \RuntimeException('Unable to copy component.');
                        $component->setDocument($copy($nested,$component));$component->table($component->model_source)->where(['id'=>$component->getKey()])->update(['component_json'=>$component->getParam('component_json')]);
                    }
                    $document->component_id=$components[$key];
                }
                $block=(new QuillBlock())->useConnectionFrom($target);$block->admin_id=$adminId;$block->setDocument($document);
                return $ids[$id]=$block->saveModel()??throw new \RuntimeException('Unable to copy block.');
            };
            foreach($document->sections as $section) $section->blocks=array_map($blockCopy,$section->blocks);
            $document->settings??=new \stdClass();$document->settings->title=$owner->name;$document->settings->description=$owner->description??'';
            return $document;
        };
        return $copy($source,$target);
    }
}
