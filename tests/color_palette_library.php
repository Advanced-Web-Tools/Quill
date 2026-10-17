<?php
// Named page palettes, shared library endpoints, defaults and persistence compatibility.
ob_start();
require __DIR__ . '/animation_library.php';

use Quill\models\QuillPage;
use Quill\models\QuillPageSnapshot;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\quillToUrpc\WorkingQuillContentSource;
use Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource;

$paletteColumns = array_map(fn($column)=>$column->getName(), $tables['quill_color_palette']);
check(array_diff(['id','name','palette_json','admin_id','created_at','updated_at','lock_version','deleted_at'],$paletteColumns)===[], 'Migration declares named global palettes with creator, dates and versioning');
$pdo->exec("CREATE TABLE quill_color_palette ($common, name TEXT NOT NULL, palette_json TEXT NOT NULL, lock_version INTEGER DEFAULT 1, deleted_at TEXT)");
$brandColors=[['id'=>'qp-primary','name'=>'Primary','value'=>'#ff0066'],['id'=>'qp-hero','name'=>'Hero','value'=>'linear-gradient(90deg, red, blue)']];
$semanticColors=[['id'=>'qp-success','name'=>'Success','value'=>'#008866'],['id'=>'qp-primary','name'=>'Primary','value'=>'#2244ff']];
$paletteDefinition=['name'=>'Shared brand','colors'=>$brandColors];
$palettePost=['_token'=>'test-csrf','definition'=>json_encode($paletteDefinition)];
libraryRequest('saveEditorPalette',[],['definition'=>json_encode($paletteDefinition)],403);
libraryRequest('saveEditorPalette',[],$palettePost,405,'GET');
libraryRequest('saveEditorPalette',[],$palettePost+['admin_id'=>'99'],422);
$brand=libraryRequest('saveEditorPalette',[],$palettePost,201)['palette'];
check($brand['admin_id']===1&&$brand['version']===1&&$brand['created_at']&&$brand['updated_at']&&count($brand['colors'])===2,'Global palettes store named colors/gradients with creator and timestamps');
$semantic=libraryRequest('saveEditorPalette',[],['_token'=>'test-csrf','definition'=>json_encode(['name'=>'Shared semantic','colors'=>$semanticColors])],201)['palette'];
$list=libraryRequest('editorPalettes',[],[],200,'GET');
check(count($list['palettes'])===2&&$list['palettes'][0]['name']==='Shared brand'&&count($list['palettes'][0]['colors'])===2,'Global selector lists named palettes with swatches');
$loadedPalette=libraryRequest('editorPalettes',['id'=>$brand['id']],[],200,'GET')['palette'];
check(json_encode($loadedPalette['definition'])===json_encode($paletteDefinition),'Global palette round-trips without losing gradient values');
foreach([
    ['name'=>'','colors'=>$brandColors],
    ['name'=>'Invalid','colors'=>[['id'=>'qp-bad','name'=>'Bad','value'=>'url(javascript:alert(1))']]],
    ['name'=>'Invalid','colors'=>[['id'=>'qp-bad','name'=>'Bad','value'=>'var(--unsafe)']]],
    ['name'=>'Invalid','colors'=>[$brandColors[0],$brandColors[0]]],
]as$invalidPalette)libraryRequest('saveEditorPalette',[],['_token'=>'test-csrf','definition'=>json_encode($invalidPalette)],422);

$paletteOwner=request('page',['_token'=>'test-csrf','name'=>'Named palette page'],201);
$paletteParams=['type'=>'page','id'=>(string)$paletteOwner['id']];
$paletteDocument=['schema_version'=>1,'sections'=>[['name'=>'main','blocks'=>[$element('p','Existing styled text',[],['style'=>'color: #ff0066;'])]]],
    'settings'=>['color_palette'=>[['id'=>'qp-legacy','name'=>'Old saved color','value'=>'#11223344']],
        'color_palettes'=>[['id'=>'qpal-local-brand','name'=>'Local brand','colors'=>$brandColors],['id'=>'qpal-local-semantic','name'=>'Local semantic','colors'=>$semanticColors]],
        'default_color_palette'=>['scope'=>'local','id'=>'qpal-local-brand']]];
$paletteDocument['sections'][0]['blocks'][0]['attributes']->style='color: #ff0066;';
$paletteDraft=documentRequest($paletteParams,['_token'=>'test-csrf','document'=>json_encode($paletteDocument),'lock_version'=>'1','mode'=>'publish'],200);
$palettePage=new QuillPage();$palettePage->selectByID($paletteOwner['id']);
$paletteEditor=(new Quill\services\editor\EditorDocumentLoader())->load($palettePage)['document'];
check(count($paletteEditor->settings->color_palettes)===2&&$paletteEditor->settings->default_color_palette->id==='qpal-local-brand'&&$paletteEditor->settings->color_palette[0]->value==='#11223344','Multiple local palettes, default choice and legacy saved colors persist together');
check((int)$pdo->query('SELECT COUNT(*) FROM quill_color_palette')->fetchColumn()===2,'Saving local palettes never promotes them implicitly');
$paletteBlockId=$palettePage->getDocument()->sections[0]->blocks[0];
$paletteBlockBefore=$pdo->query('SELECT block_json FROM quill_block WHERE id='.(int)$paletteBlockId)->fetchColumn();
foreach(['duplicate-id','duplicate-name','missing-local-default','invalid-global-default','unsupported-field']as$case){
    $invalid=json_decode(json_encode($paletteDocument));
    if($case==='duplicate-id')$invalid->settings->color_palettes[1]->id='qpal-local-brand';
    if($case==='duplicate-name')$invalid->settings->color_palettes[1]->name='LOCAL BRAND';
    if($case==='missing-local-default')$invalid->settings->default_color_palette->id='qpal-missing';
    if($case==='invalid-global-default')$invalid->settings->default_color_palette=(object)['scope'=>'global','id'=>-1];
    if($case==='unsupported-field')$invalid->settings->color_palettes[0]->script='unsafe';
    documentRequest($paletteParams,['_token'=>'test-csrf','document'=>json_encode($invalid),'lock_version'=>'2'],422);
}
$promoted=libraryRequest('saveEditorPalette',[],['_token'=>'test-csrf','definition'=>json_encode(['name'=>'Local brand','colors'=>$brandColors])],201)['palette'];
$paletteDocument['settings']['color_palettes']=[$paletteDocument['settings']['color_palettes'][1]];
$paletteDocument['settings']['default_color_palette']=['scope'=>'global','id'=>$promoted['id']];
documentRequest($paletteParams,['_token'=>'test-csrf','document'=>json_encode($paletteDocument),'lock_version'=>'2'],200);
$palettePage->selectByID($paletteOwner['id']);
check($palettePage->getDocument()->settings->default_color_palette->scope==='global'&&count($palettePage->getDocument()->settings->color_palettes)===1,'Promotion can replace a local collection with a global default reference');
$revisedDefinition=['name'=>'Shared brand revised','colors'=>[['id'=>'qp-primary','name'=>'Primary','value'=>'#00ff00']]];
$revised=libraryRequest('saveEditorPalette',['id'=>$brand['id']],['_token'=>'test-csrf','lock_version'=>'1','definition'=>json_encode($revisedDefinition)],200)['palette'];
check($revised['id']===$brand['id']&&$revised['version']===2&&$revised['admin_id']===1&&$revised['created_at']===$brand['created_at'],'Replace keeps global palette identity and original creator/creation date');
libraryRequest('saveEditorPalette',['id'=>$brand['id']],$palettePost+['lock_version'=>'1'],409);
$paletteCopy=libraryRequest('saveEditorPalette',[],['_token'=>'test-csrf','definition'=>json_encode($revisedDefinition)],201)['palette'];
check($paletteCopy['id']!==$brand['id'],'Save as new creates an independent named palette');
$paletteSnapshot=new QuillPageSnapshot();$paletteSnapshot->selectByID($paletteDraft['snapshot_id']);
$palettePublished=(new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($paletteSnapshot));
check(str_contains($palettePublished->document->getBlocks('main')[0]->getBlockAttributes()['style'],'#ff0066'),'Global palette changes do not recolor existing published blocks');
check($pdo->query('SELECT block_json FROM quill_block WHERE id='.(int)$paletteBlockId)->fetchColumn()===$paletteBlockBefore,'Palette library operations leave original structural block JSON unchanged');
libraryRequest('deleteEditorPalette',['id'=>$promoted['id']],['_token'=>'bad','lock_version'=>'1'],403);
libraryRequest('deleteEditorPalette',['id'=>$promoted['id']],['_token'=>'test-csrf','lock_version'=>'99'],409);
libraryRequest('deleteEditorPalette',['id'=>$promoted['id']],['_token'=>'test-csrf','lock_version'=>'1'],200);
libraryRequest('editorPalettes',['id'=>$promoted['id']],[],404,'GET');
check((new QuillToUrpcConverter())->convert(new WorkingQuillContentSource($palettePage))->document->getBlocks('main')[0]->getBlockContent()==='Existing styled text','Missing global default palettes never prevent page rendering');
$paletteDocument['settings']['default_color_palette']=['scope'=>'global','id'=>99999];
documentRequest($paletteParams,['_token'=>'test-csrf','document'=>json_encode($paletteDocument),'lock_version'=>'3'],200);
echo "Passed named local/global palette CRUD, defaults, compatibility and persistence checks; $count total checks.\n";
ob_end_flush();
