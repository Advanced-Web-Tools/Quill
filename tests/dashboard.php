<?php
ob_start();
require __DIR__ . '/editor_save.php';

use Quill\models\{QuillPage,QuillPageSnapshot,QuillPageTemplate};
use Quill\services\editor\{PageLibrary,EditorDocumentLoader};
use Quill\services\conversion\quillToUrpc\{QuillToUrpcConverter,WorkingQuillContentSource};

$pdo->exec('CREATE TABLE quill_page_template (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, description TEXT, template_json TEXT NOT NULL, admin_id INTEGER NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, lock_version INTEGER DEFAULT 1, deleted_at TEXT)');
$eventDispatcher=$viewController->eventDispatcher;
$dashboardXmlErrors=libxml_use_internal_errors(true);
function dashboardRequest(string $method,array $params,array $post,int $status,string $http='POST'): array {
    global $controller;
    $_POST=$post;$_SERVER['REQUEST_METHOD']=$http;$response=$controller->$method($params);
    $actual=(new ReflectionProperty(response\Response::class,'statusCode'))->getValue($response);
    $payload=(new ReflectionProperty(response\Response::class,'payload'))->getValue($response);
    check($actual===$status,"$method expected $status, got $actual: ".json_encode($payload));return $payload;
}
function dashboardPage(int $id): QuillPage { $page=new QuillPage();$page->selectByID($id);return $page; }
$token=['_token'=>'test-csrf'];
$privateOwner=request('page',$token+['name'=>'Original design'],201);
$libraryComponent=request('component',$token+['name'=>'Library component'],201);
$originalComponent=['schema_version'=>1,'sections'=>[['name'=>'main','blocks'=>[$element('p','Original component content')]]]];
documentRequest(['type'=>'component','id'=>(string)$libraryComponent['id']],$token+['document'=>json_encode($originalComponent),'lock_version'=>'1','mode'=>'publish'],200);
$componentNode=(object)['descriptor'=>(object)['name'=>'quill-component','props'=>new stdClass(),'renderer'=>''],'attributes'=>new stdClass(),'content'=>'','children'=>[],
    '_quill'=>(object)['component'=>(object)['id'=>$libraryComponent['id'],'instanceId'=>'qc-dashboard-component']]];
$libraryDocument=['schema_version'=>1,'settings'=>(object)['title'=>'Original design','color_palette'=>[(object)['id'=>'qp-brand','name'=>'Brand','value'=>'#123456']]],'sections'=>[['name'=>'main','blocks'=>[$element('h1','Original page content'),$componentNode]]]];
$firstVersion=documentRequest(['type'=>'page','id'=>(string)$privateOwner['id']],$token+['document'=>json_encode($libraryDocument),'lock_version'=>'1','mode'=>'publish'],200);
$template=dashboardRequest('savePageTemplate',['id'=>(string)$privateOwner['id']],$token+['name'=>'My private design','description'=>'A saved starting point','lock_version'=>'2'],201);
$library=new PageLibrary();
check(count($library->templates(1))===1 && count($library->templates(2))===0,'Templates are scoped to their admin');
// Change the live working component after both the template and page snapshot have captured it.
$originalComponent['sections'][0]['blocks'][0]['content']='Component changed later';
documentRequest(['type'=>'component','id'=>(string)$libraryComponent['id']],$token+['document'=>json_encode($originalComponent),'lock_version'=>'2','mode'=>'draft'],200);
$instance=request('page',$token+['name'=>'Page from design','template_id'=>(string)$template['id']],201);
$instancePage=dashboardPage($instance['id']);$instanceLoaded=(new EditorDocumentLoader())->load($instancePage);
check(str_contains(json_encode($instanceLoaded['document']),'Original component content')&&!str_contains(json_encode($instanceLoaded['document']),'Component changed later'),'Template instances freeze component content independently of later source changes');
check($instancePage->status==='draft'&&$instancePage->name==='Page from design'&&$instanceLoaded['document']->settings->color_palette[0]->value==='#123456','Templates produce new drafts with chosen titles and saved styling/palette');
$blocks=$instancePage->getDocument()->sections[0]->blocks;
check(count($blocks)===2 && $instancePage->getKey()!==$privateOwner['id'],'Template creates one independent page with a complete copied block graph');
// Another authenticated admin cannot read, use, rename or delete a private template.
$pdo->exec("INSERT INTO awt_admin VALUES (2,'other-token','other-admin',NULL,0,'')");$_SESSION['admin']=['id'=>2,'token'=>'other-token'];
request('page',$token+['name'=>'Forbidden copy','template_id'=>(string)$template['id']],404);
request('updateTemplate',$token+['id'=>(string)$template['id'],'lock_version'=>'1','name'=>'Stolen','description'=>''],404);
request('deleteTemplate',$token+['id'=>(string)$template['id'],'lock_version'=>'1'],404);
check($viewController->templates([])->bundle['templates']===[],'Templates dashboard never exposes another admin\'s templates');
$_SESSION['admin']=['id'=>1,'token'=>'authenticated-admin'];
request('updateTemplate',$token+['id'=>(string)$template['id'],'lock_version'=>'1','name'=>'Renamed template','description'=>'Updated'],200);
$draftDocument=$libraryDocument;$draftDocument['sections'][0]['blocks'][0]['content']='Newer draft content';$draftDocument['settings']->title='New draft title';
documentRequest(['type'=>'page','id'=>(string)$privateOwner['id']],$token+['document'=>json_encode($draftDocument),'lock_version'=>'2','mode'=>'draft'],200);
$draftPage=dashboardPage($privateOwner['id']);
$preview=$viewController->preview(['id'=>(string)$privateOwner['id']]);
$previewHtml=(new ReflectionProperty(response\Response::class,'payload'))->getValue($preview);
$previewHeaders=(new ReflectionProperty(response\Response::class,'headers'))->getValue($preview);
check(str_contains($previewHtml,'Newer draft content')&&str_contains($previewHtml,'Component changed later')&&!str_contains($previewHtml,'Original page content'),'Private draft preview renders current working content even when a published version exists');
check($previewHeaders['Cache-Control']==='private, no-store'&&$previewHeaders['X-Robots-Tag']==='noindex, nofollow','Draft previews are non-cacheable and excluded from indexing');
$public=new Quill\controllers\PublicPageController();$public->setContext(new context\Context(dirname(__DIR__).'/Quill','Quill','1'));$public->setPage($draftPage);
check(str_contains($public->render(),'Original page content')&&!str_contains($public->render(),'Newer draft content'),'Draft preview does not alter the published page');
dashboardRequest('restorePageSnapshot',['id'=>(string)$privateOwner['id'],'snapshotId'=>(string)$firstVersion['snapshot_id']],$token+['lock_version'=>'3'],200);
$restored=dashboardPage($privateOwner['id']);$restoredLoaded=(new EditorDocumentLoader())->load($restored);
check(str_contains(json_encode($restoredLoaded['document']),'Original page content')&&str_contains(json_encode($restoredLoaded['document']),'Original component content')&&!str_contains(json_encode($restoredLoaded['document']),'Component changed later'),'Rollback restores exact snapshot page and component content, without live-component drift');
check($restored->status==='draft'&&$restored->lock_version===4&&$restored->published_snapshot_id===$firstVersion['snapshot_id'],'Rollback uses optimistic locking and leaves the published snapshot unchanged');
dashboardRequest('restorePageSnapshot',['id'=>(string)$privateOwner['id'],'snapshotId'=>(string)$firstVersion['snapshot_id']],$token+['lock_version'=>'3'],409);
dashboardRequest('restorePageSnapshot',['id'=>(string)$instance['id'],'snapshotId'=>(string)$firstVersion['snapshot_id']],$token+['lock_version'=>'1'],404);
dashboardRequest('deletePageSnapshot',['id'=>(string)$privateOwner['id'],'snapshotId'=>(string)$firstVersion['snapshot_id']],$token+['lock_version'=>'4'],409);
// Publish the restored draft, then delete the now-historical first version.
$restoredTransport=$restoredLoaded['document'];
$secondVersion=documentRequest(['type'=>'page','id'=>(string)$privateOwner['id']],$token+['document'=>json_encode($restoredTransport),'lock_version'=>'4','mode'=>'publish'],200);
dashboardRequest('deletePageSnapshot',['id'=>(string)$privateOwner['id'],'snapshotId'=>(string)$firstVersion['snapshot_id']],$token+['lock_version'=>'5'],200);
check((new QuillPageSnapshot())->newQuery()->where(['id'=>$firstVersion['snapshot_id']])->firstModel()===null,'Historical snapshot rows can be deleted');
check((int)$pdo->query('SELECT COUNT(*) FROM quill_page_snapshot_block WHERE page_snapshot_id='.(int)$firstVersion['snapshot_id'])->fetchColumn()===0,'Deleting a snapshot removes its block manifest');
check(dashboardPage($privateOwner['id'])->published_snapshot_id===$secondVersion['snapshot_id'],'Deleting history never changes the live version');
dashboardRequest('savePageTemplate',['id'=>(string)$privateOwner['id']],['_token'=>'forged','lock_version'=>'6','name'=>'Rejected'],403);
dashboardRequest('restorePageSnapshot',['id'=>(string)$privateOwner['id'],'snapshotId'=>(string)$secondVersion['snapshot_id']],$token+['lock_version'=>'6'],405,'GET');
$history=$viewController->history(['id'=>(string)$privateOwner['id']]);
check(count($history->bundle['snapshots'])===1&&$history->bundle['authors'][1]==='tester','History includes exact page versions and creator names');
request('deleteTemplate',$token+['id'=>(string)$template['id'],'lock_version'=>'2'],200);
check($library->templates(1)===[],'Deleting a private template removes it from future use');
request('page',$token+['name'=>'Deleted template copy','template_id'=>(string)$template['id']],404);
libxml_clear_errors();libxml_use_internal_errors($dashboardXmlErrors);
echo ob_get_clean();echo "Passed dashboard library, history and preview checks; $count total checks.\n";
