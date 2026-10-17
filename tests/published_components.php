<?php
// Public component updates must not require publishing every host page.
ob_start();
require __DIR__ . '/shared_components.php';

$publicComponentStart = $count;
$eventDispatcher = new event\EventDispatcher();
function sharedPublic(int $id): Quill\controllers\PublicPageController {
    $renderer = new Quill\controllers\PublicPageController();
    $renderer->setContext(new context\Context(dirname(__DIR__).'/Quill','Quill','1'));
    $renderer->setPage(loadedPage($id));
    return $renderer;
}
$publicHost = sharedPublic($firstHost['id']);
$nestedPublic = sharedPublic($nestedHost['id']);
check(str_contains($publicHost->render(),'Updated at source') && !str_contains($publicHost->render(),'Newer working source'),'Legacy public hosts retain captured component content and exclude newer working drafts');
$hostBefore = loadedPage($firstHost['id']);$nestedBefore=loadedPage($nestedHost['id']);
$hostSnapshot=$hostBefore->published_snapshot_id;$hostVersion=$hostBefore->lock_version;$hostJson=$hostBefore->page_json;
$nestedSnapshotId=$nestedBefore->published_snapshot_id;
$master=loadedComponent($sharedId);$document=(new Quill\services\editor\EditorComponentLibrary())->document($master);
$document->sections[0]->blocks[0]->children[0]->content='Published everywhere';
$document->sections[0]->blocks[0]->attributes->style='background-color:#2468ac;padding:17px';
$document->assets->styles[]='updated-shared.css';
$saved=documentRequest(['type'=>'component','id'=>(string)$sharedId],['_token'=>'test-csrf','mode'=>'publish','lock_version'=>(string)$master->lock_version,'document'=>json_encode($document)],200);
$html=$publicHost->render();
check(substr_count($html,'Published everywhere')===2,'Publishing a component updates every instance on an already-published host');
check(str_contains($html,'#2468ac')&&str_contains($html,'updated-shared.css'),'Updated component styles and dependency assets reach public pages');
check(str_contains($nestedPublic->render(),'Published everywhere'),'Independently published nested components update through captured parent component snapshots');
$after=loadedPage($firstHost['id']);
check($after->published_snapshot_id===$hostSnapshot&&$after->lock_version===$hostVersion&&$after->page_json===$hostJson,'Component publish does not rewrite or republish the host page');
check(loadedPage($nestedHost['id'])->published_snapshot_id===$nestedSnapshotId,'Nested component propagation preserves the host snapshot');
check((new Quill\services\conversion\quillToUrpc\QuillToUrpcConverter())->convert(new Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource($snapshot))->document->getBlocks('main')[0]->getBlockChildren()[0]->getBlockContent()==='Updated at source','Historical snapshot conversion still uses exact original component manifests');

$master=loadedComponent($sharedId);$draft=(new Quill\services\editor\EditorComponentLibrary())->document($master);
$draft->sections[0]->blocks[0]->children[0]->content='Unpublished secret draft';
documentRequest(['type'=>'component','id'=>(string)$sharedId],['_token'=>'test-csrf','mode'=>'draft','lock_version'=>(string)$master->lock_version,'document'=>json_encode($draft)],200);
check(str_contains($publicHost->render(),'Published everywhere')&&!str_contains($publicHost->render(),'Unpublished secret draft'),'Later component drafts do not leak onto public pages');
check(str_contains($nestedPublic->render(),'Published everywhere'),'Nested public instances also keep the last published version');

// Removal stores only a page reference change; the source row and other hosts survive.
$componentBefore=loadedComponent($sharedId);$sourceJson=$componentBefore->component_json;$sourceVersion=$componentBefore->lock_version;
$page=loadedPage($firstHost['id']);$pageDocument=(new Quill\services\editor\EditorDocumentLoader())->load($page)['document'];
array_shift($pageDocument->sections[0]->blocks);
saveSharedPage($firstHost['id'],$pageDocument,$page->lock_version,'publish');
check(substr_count(sharedPublic($firstHost['id'])->render(),'Published everywhere')===1,'Removing one instance affects only that page reference');
$sourceAfter=loadedComponent($sharedId);
check($sourceAfter->component_json===$sourceJson&&$sourceAfter->lock_version===$sourceVersion&&$sourceAfter->deleted_at===null,'Removing a page instance neither edits nor deletes the saved component');
check(str_contains($nestedPublic->render(),'Published everywhere'),'Other hosts keep their component instances');

// A bad publication pointer must never resolve to a different component or expose a draft.
$database=new database\DatabaseManager();
$database->table('quill_component')->where(['id'=>$sharedId])->update(['published_snapshot_id'=>999999]);
try {$publicHost->render();throw new RuntimeException('Expected exact component publication error');}
catch(Quill\services\conversion\shared\ConversionException $error){check(str_contains($error->getMessage(),'published snapshot for component'),'Missing published component snapshot gives a precise conversion error');}
$database->table('quill_component')->where(['id'=>$sharedId])->update(['published_snapshot_id'=>$saved['snapshot_id']]);

echo 'Passed '.($count-$publicComponentStart)." published component propagation / instance removal checks.\n";
ob_end_flush();
