<?php
// Run: php tests/connect_data.php. Real AWT schema registry, Model/ModelCollection and URPC.
ob_start();
require __DIR__ . '/public_page.php';

use Quill\services\data\DataConnection;
use Quill\services\data\DataSourceCatalog;
use Quill\services\data\ModelConnectionRecords;
use Quill\services\rendering\ConnectedDataRenderer;
use Quill\models\collections\ConnectedRecordCollection;
use URPC\api\URPCBlock;
use URPC\api\URPCBlockDescriptor;
use URPC\api\URPCDocument;

$connectionStart = $count;
$schema = database\creator\TableWizard::create();
$schema->addColumn(database\creator\ColumnCreator::INT('id')->primary()->autoIncrement());
foreach (['slug','title','url','alt'] as $column) $schema->addColumn(database\creator\ColumnCreator::TEXT($column)->nullable());
check($schema->createTable('quill_test_products'), 'TableWizard creates and registers a data source');
$database = new database\DatabaseManager();
for ($i=1; $i<=7; $i++) $database->table('quill_test_products')->insert(['slug'=>'product-'.$i,'title'=>'Product <'.$i.'>','url'=>'/images/'.$i.'.jpg','alt'=>$i===2?null:'Image '.$i])->executeInsert();
$catalog = new DataSourceCatalog();
check($catalog->all() === [['name'=>'quill_test_products','columns'=>['id','slug','title','url','alt']]], 'Catalog uses framework schema and contains no record data');
$props = ['source'=>'quill_test_products','select'=>'slug','default_value'=>'product-2','bind'=>'',
    'set'=>['attributes'=>[['name'=>'title','value'=>'title']], 'content'=>['content'=>'title']]];
$block = new URPCBlock(new URPCBlockDescriptor('p',$props,DataConnection::RENDERER));
$block->setBlockContent('Authored fallback');
$block->setBlockAttributes(['class'=>'product','id'=>'item']);
$doc = new URPCDocument();$doc->addBlock('main',$block);
$public = new Quill\controllers\PublicPageController();$public->setSourceNamespace('Quill/test-data');$public->setDocument($doc);
$original = (new Quill\services\conversion\quillToUrpc\UrpcJsonSerializer())->serializeSection($doc,'main');
$html = $public->render();
check(str_contains($html,'Product &lt;2&gt;') && str_contains($html,'title="Product &lt;2&gt;"'), 'Single matching value fills text and attributes using escaped URPC rendering');
check($public->render()===$html, 'Repeated rendering keeps deterministic output');
check((new Quill\services\conversion\quillToUrpc\UrpcJsonSerializer())->serializeSection($doc,'main')===$original,'Rendering leaves authored descriptor and tree unchanged');
$normalRenderer = new URPC\service\renderer\URPC();$normalRenderer->setSourceNamespace('URPC/direct');$normalRenderer->setDocument($doc);
check(str_contains($normalRenderer->render(),'Product &lt;2&gt;'),'Renderer implements the actual URPC block processor contract');

$routeProps = $props;$routeProps['mode']='route';$routeProps['bind']='slug';
$block->getBlockDescriptor()->setBlockProps($routeProps);
check(str_contains($public->index(['slug'=>'product-4'])->render(),'Product &lt;4&gt;'),'Route parameters select the matching row');
check(str_contains($public->index([])->render(),'Product &lt;2&gt;'),'Missing route value uses configured default');
check(str_contains($public->index(['slug'=>['bad']])->render(),'Product &lt;2&gt;'),'Non-scalar route values cannot enter the query');
check(str_contains($public->index(['slug'=>"' OR 1=1 --"])->render(),'Authored fallback'),'Route values use bound framework query parameters');
check(str_contains($public->index(['slug'=>'missing'])->render(),'Authored fallback'),'No matching route record preserves authored fallback');

$collectionProps=$props;$collectionProps['mode']='collection';$collectionProps['sort_by']='id';$collectionProps['sort']='desc';
$block->getBlockDescriptor()->setBlockProps($collectionProps);
$rows = (new ModelConnectionRecords())->find(DataConnection::parse($collectionProps),[]);
check($rows instanceof ConnectedRecordCollection && $rows instanceof model\ModelCollection && count($rows)===5,'Collection uses bounded AWT ModelCollection with default Show 5');
check(array_map(static fn($row)=>$row->getParam('id'),iterator_to_array($rows))===[7,6,5,4,3],'Collection preserves descending sort after framework hydration');
$html=$public->render();
check(substr_count($html,'<p ')===5 && !str_contains($html,'<div'),'Collection creates sibling copies without HTML wrappers');
check(strpos($html,'Product &lt;7&gt;')<strpos($html,'Product &lt;3&gt;'),'Collection displays descending record order');
check(substr_count($html,'id="item"')===1 && substr_count($html,'--quill-record-')===4,'Repeated blocks receive unique author HTML IDs');
$uids=array_map(static fn($item)=>$item->getBlockDescriptor()->getBlockUid(),array_values($public->getDocument()->getBlocks('main')));
check(count(array_unique($uids))===5,'URPC assigns distinct identities to expanded blocks');
$collectionProps['show']=1;$block->getBlockDescriptor()->setBlockProps($collectionProps);
check(substr_count($public->render(),'<p ')===1,'Show 1 does not duplicate the block');
$collectionProps['show']='all';$collectionProps['sort']='asc';$block->getBlockDescriptor()->setBlockProps($collectionProps);
$html=$public->render();
check(substr_count($html,'<p ')===7 && strpos($html,'Product &lt;1&gt;')<strpos($html,'Product &lt;7&gt;'),'All returns all records in ascending order');
$eventDispatcher->addListener(URPC\api\events\BeforeRenderEvent::NAME,new Quill\services\rendering\ConnectedDataRenderListener());
check(substr_count($normalRenderer->render(),'<p ')===7,'Package listener also expands sibling collections in the base URPC renderer');
check($block->getBlockDescriptor()->getBlockRenderer()===DataConnection::RENDERER,'Base URPC integration leaves the source renderer configuration untouched');

$imageProps=$props;$imageProps['set']=['attributes'=>[['name'=>'src','value'=>'url'],['name'=>'alt','value'=>'alt']],'content'=>['content'=>'title']];
$image=new URPCBlock(new URPCBlockDescriptor('img',$imageProps,DataConnection::RENDERER));$image->setBlockAttributes(['src'=>'fallback.jpg','alt'=>'Existing alt']);
$imageDoc=new URPCDocument();$imageDoc->addBlock('main',$image);$public->setDocument($imageDoc);
check($public->render()==='<img src="/images/2.jpg" alt="Existing alt">','Void elements accept mapped attributes and keep null-value fallback without adding content');
$database->table('quill_test_products')->where(['id'=>2])->update(['url'=>'javascript:alert(1)']);
check(str_contains($public->render(),'src="fallback.jpg"'),'Unsafe database URL keeps the fallback attribute');

$nested = new URPCBlock(new URPCBlockDescriptor('section'));
$nested->addBlockChild($block);$nestedDoc=new URPCDocument();$nestedDoc->addBlock('main',$nested);$public->setDocument($nestedDoc);
check(substr_count($public->render(),'<section>')===1 && substr_count($public->render(),'<p ')===7,'Nested collection expands inside its original layout parent');
$missing=$props;$missing['source']='missing_source';$block->getBlockDescriptor()->setBlockProps($missing);$public->setDocument($doc);
check(str_contains($public->render(),'Authored fallback'),'Unavailable source leaves authored fallback intact');
$missing=$props;$missing['set']['content']['content']='missing_column';$block->getBlockDescriptor()->setBlockProps($missing);
check(str_contains($public->render(),'Authored fallback'),'Unavailable mapped column leaves authored fallback intact');

foreach ([['source'=>'products;DROP TABLE x'],['select'=>'id OR 1=1'],['show'=>0],['show'=>-1],['show'=>'5'],['sort'=>'DROP'],['mode'=>'sql'],['default_value'=>[]],['set'=>['attributes'=>[['name'=>'onclick','value'=>'title']]]],['set'=>['attributes'=>[['name'=>'style','value'=>'title']]]]] as $invalid) {
    try {DataConnection::parse(array_replace($props,$invalid));throw new RuntimeException('Expected invalid connection to be rejected');}
    catch(InvalidArgumentException $error){check(true,'Invalid query configuration is rejected before querying');}
}

// Existing draft/save/publish lifecycle stores only descriptor configuration and authored fallback.
$owner=request('page',['_token'=>'test-csrf','name'=>'Connected data'],201);
$transport=['schema_version'=>1,'sections'=>[['name'=>'main','blocks'=>[['descriptor'=>['name'=>'p','props'=>$props,'renderer'=>DataConnection::RENDERER],'attributes'=>new stdClass(),'content'=>'Saved fallback','children'=>[]]]]]];
$result=documentRequest(['type'=>'page','id'=>(string)$owner['id']],['_token'=>'test-csrf','lock_version'=>'1','mode'=>'publish','document'=>json_encode($transport)],200);
$savedPage=new Quill\models\QuillPage();$savedPage->selectByID($owner['id']);
$loaded=(new Quill\services\editor\EditorDocumentLoader())->load($savedPage)['document'];
check($loaded->sections[0]->blocks[0]->descriptor->props->source==='quill_test_products' && $loaded->sections[0]->blocks[0]->content==='Saved fallback','Reopening preserves connection descriptor and authored fallback');
check(str_contains(publicController($savedPage)->render(),'Product &lt;2&gt;'),'Published snapshots resolve current source records through URPC');
$routesModel=new Quill\models\QuillDynamicRoute();$routesModel->admin_id=1;$routesModel->page_id=$savedPage->getKey();$routesModel->path='/catalog/{slug}';$routesModel->saveModel();
$previous=libxml_use_internal_errors(true);$view=$viewController->editor(['type'=>'page','id'=>(string)$owner['id']]);libxml_clear_errors();libxml_use_internal_errors($previous);
check($view->bundle['editorDataRoutes']===[['path'=>'/catalog/{slug}','parameters'=>['slug']]],'Editor route selector contains only routes assigned to this page');
check($view->bundle['editorDataSources']===$catalog->all(),'Authenticated editor receives registered schema metadata');
$transport['sections'][0]['blocks'][0]['descriptor']['props']['source']='bad;sql';
documentRequest(['type'=>'page','id'=>(string)$owner['id']],['_token'=>'test-csrf','lock_version'=>'2','mode'=>'draft','document'=>json_encode($transport)],422);

$reserved=database\creator\TableWizard::create();
$reserved->addColumn(database\creator\ColumnCreator::INT('id')->primary()->autoIncrement())->addColumn(database\creator\ColumnCreator::TEXT('order'));
check($reserved->createTable('order'),'Framework supports registered reserved-word table and column names');
$database->table('`order`')->insert(['`order`'=>'Reserved source'])->executeInsert();
$reservedProps=['source'=>'order','select'=>'id','default_value'=>1,'set'=>['content'=>['content'=>'order']]];
$reservedBlock=new URPCBlock(new URPCBlockDescriptor('p',$reservedProps,DataConnection::RENDERER));
$reservedDoc=new URPCDocument();$reservedDoc->addBlock('main',$reservedBlock);$public->setDocument($reservedDoc);
check($public->render()==='<p>Reserved source</p>','Single queries quote registered table and column identifiers through the framework');
$reservedProps['mode']='collection';$reservedProps['sort_by']='order';$reservedBlock->getBlockDescriptor()->setBlockProps($reservedProps);
check($public->render()==='<p>Reserved source</p>','ModelCollection also supports reserved-word sort columns');
$database->table('`order`')->where(['id'=>1])->update(['`order`'=>'Fresh source']);
check($public->render()==='<p>Fresh source</p>','Source writes are reflected on the next collection render');

echo 'Passed '.($count-$connectionStart)." Connect data / real ModelCollection / URPC checks.\n";
ob_end_flush();
