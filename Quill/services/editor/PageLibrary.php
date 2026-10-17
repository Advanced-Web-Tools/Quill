<?php
namespace Quill\services\editor;

use Quill\models\{QuillPage,QuillPageTemplate,QuillPageSnapshot};
use Quill\services\conversion\quillToUrpc\{WorkingQuillContentSource,SnapshotQuillContentSource};

final class PageLibrary
{
    public function templates(int $adminId): array { return (new QuillPageTemplate())->newQuery()->where(['admin_id'=>$adminId,'deleted_at'=>null])->orderBy('updated_at','DESC')->getModels(); }
    public function template(int $id,int $adminId): QuillPageTemplate
    {
        return (new QuillPageTemplate())->newQuery()->where(['id'=>$id,'admin_id'=>$adminId,'deleted_at'=>null])->firstModel()??throw new \OutOfBoundsException('Template not found.');
    }
    public function saveTemplate(QuillPage $page,string $name,?string $description,int $version,int $adminId): QuillPageTemplate
    {
        if($page->lock_version!==$version)throw new \DomainException('This page changed. Reload before saving a template.');
        if($page->source_type==='urpc')throw new \InvalidArgumentException('Convert this page to Quill before saving a template.');
        $archive=get_object_vars($page->getDocument())===[]
            ?(object)['schema_version'=>1,'root'=>'empty','sources'=>(object)['empty'=>(object)['document'=>(object)['schema_version'=>1,'sections'=>[(object)['name'=>'main','blocks'=>[]]]],'blocks'=>new \stdClass(),'components'=>new \stdClass()]]]
            :(new ContentArchive())->capture(new WorkingQuillContentSource($page));
        $template=new QuillPageTemplate();$template->name=$name;$template->description=$description;$template->admin_id=$adminId;$template->setDocument($archive);
        if($template->saveModel()===null)throw new \RuntimeException('Unable to save template.');
        return $template;
    }
    public function createFromTemplate(int $id,int $adminId,string $name,?string $description): QuillPage
    {
        $template=$this->template($id,$adminId);
        return $template->transaction(function()use($template,$adminId,$name,$description){
            $page=(new QuillPage())->useConnectionFrom($template);$page->name=$name;$page->description=$description;$page->admin_id=$adminId;$page->status='draft';$page->source_type='quill';$page->setDocument(new \stdClass());
            if($page->saveModel()===null)throw new \RuntimeException('Unable to create page.');
            $page->setDocument((new ContentArchive())->materialize($template->getDocument(),$page,$adminId));$page->table($page->model_source)->where(['id'=>$page->getKey()])->update(['page_json'=>$page->getParam('page_json')]);return $page;
        });
    }
    public function restore(QuillPage $page,QuillPageSnapshot $snapshot,int $version,int $adminId): void
    {
        if($snapshot->page_id!==$page->getKey())throw new \OutOfBoundsException('Version not found.');
        $archive=(new ContentArchive())->capture(new SnapshotQuillContentSource($snapshot));
        $page->transaction(function()use($page,$snapshot,$version,$adminId,$archive){
            $changed=$page->table($page->model_source)->where(['id'=>$page->getKey(),'lock_version'=>$version,'deleted_at'=>null])->update(['lock_version'=>$version+1,'updated_at'=>date('Y-m-d H:i:s')]);
            if(!$changed)throw new \DomainException('This page changed. Reload before restoring a version.');
            $page->name=$snapshot->name;$page->description=$snapshot->description;
            $document=(new ContentArchive())->materialize($archive,$page,$adminId);
            $page->table($page->model_source)->where(['id'=>$page->getKey()])->update(['name'=>$snapshot->name,'description'=>$snapshot->description,'source_type'=>'quill','source_key'=>null,'status'=>'draft','page_json'=>json_encode($document,JSON_THROW_ON_ERROR)]);
        });
    }
    public function deleteSnapshot(QuillPage $page,QuillPageSnapshot $snapshot,int $version): void
    {
        if($snapshot->page_id!==$page->getKey())throw new \OutOfBoundsException('Version not found.');
        $page->transaction(function()use($page,$snapshot,$version){
            $fresh=$page->newQuery()->where(['id'=>$page->getKey(),'deleted_at'=>null])->firstModel()??throw new \OutOfBoundsException('Page not found.');
            if($fresh->published_snapshot_id===$snapshot->getKey())throw new \DomainException('This version is currently live and cannot be deleted.');
            if(!$page->table($page->model_source)->where(['id'=>$page->getKey(),'lock_version'=>$version,'deleted_at'=>null])->update(['lock_version'=>$version+1]))throw new \DomainException('This page changed. Reload before deleting a version.');
            $blockLinks=$snapshot->blockLinks();
            $page->table('quill_page_snapshot_block')->where(['page_snapshot_id'=>$snapshot->getKey()])->delete();
            $page->table('quill_page_snapshot_component')->where(['page_snapshot_id'=>$snapshot->getKey()])->delete();
            if(!$snapshot->deleteModel())throw new \DomainException('This version is no longer available.');
            foreach($blockLinks as $link) {
                $shared=false;
                foreach([\Quill\models\QuillPageSnapshotBlock::class,\Quill\models\QuillComponentSnapshotBlock::class] as $class) {
                    if((new $class())->useConnectionFrom($page)->newQuery()->where(['block_snapshot_id'=>$link->block_snapshot_id])->getModels(1)!==[])$shared=true;
                }
                if(!$shared)$page->table('quill_block_snapshot')->where(['id'=>$link->block_snapshot_id])->delete();
            }
        });
    }
}
