<?php
// Real authenticated endpoints, SQLite persistence, mapper and working/published renderers.
ob_start();
require __DIR__ . '/animation_studio.php';

use Quill\models\QuillAnimation;
use Quill\models\QuillPage;
use Quill\models\QuillPageSnapshot;
use Quill\services\animations\AnimationLibrary;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\quillToUrpc\WorkingQuillContentSource;
use Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource;

$tables = (new ReflectionProperty(Quill\services\Migration::class, 'tables'))->getValue(new Quill\services\Migration());
$animationColumns = array_map(fn ($column) => $column->getName(), $tables['quill_animation']);
check(array_diff(['id','name','animation_json','admin_id','created_at','updated_at','lock_version','deleted_at'], $animationColumns) === [], 'Install/update migration declares the reusable animation table and creator/timestamps');
$pdo->exec("CREATE TABLE quill_animation ($common, name TEXT NOT NULL, animation_json TEXT NOT NULL, lock_version INTEGER DEFAULT 1, deleted_at TEXT)");
function libraryRequest(string $action, array $params, array $post, int $expected, string $method = 'POST'): array
{
    global $controller;
    $_POST = $post; $_SERVER['REQUEST_METHOD'] = $method;
    $response = $controller->$action($params);
    $status = (new ReflectionProperty(response\Response::class, 'statusCode'))->getValue($response);
    $payload = (new ReflectionProperty(response\Response::class, 'payload'))->getValue($response);
    check($status === $expected, "$action expected $expected: " . json_encode($payload));
    return $payload['data'] ?? $payload;
}
$first = ['id' => 'qanim-library-fade', 'name' => 'Library fade', 'duration' => 1200, 'delay' => 0, 'iterations' => 1, 'direction' => 'normal', 'fillMode' => 'both', 'easing' => 'linear',
    'tracks' => (object) ['opacity' => [['time' => 0, 'value' => '0'], ['time' => 1200, 'value' => '1']]]];
$libraryPost = ['_token' => 'test-csrf', 'definition' => json_encode($first)];
libraryRequest('saveEditorAnimation', [], ['definition' => json_encode($first)], 403);
libraryRequest('saveEditorAnimation', [], $libraryPost, 405, 'GET');
libraryRequest('saveEditorAnimation', [], $libraryPost + ['admin_id' => '999'], 422);
libraryRequest('saveEditorAnimation', [], ['_token' => 'test-csrf','definition' => '{}'], 422);
$fade = libraryRequest('saveEditorAnimation', [], $libraryPost, 201)['animation'];
check($fade['id'] > 0 && $fade['admin_id'] === 1 && $fade['version'] === 1 && $fade['created_at'] && $fade['updated_at'] && $fade['definition']->tracks->opacity[1]->value === '1', 'Creation stores compatible animation JSON with creator and timestamps');
$second = $first; $second['id'] = 'qanim-library-spin'; $second['name'] = 'Library spin'; $second['duration'] = 600;
$second['tracks'] = (object) ['rotate' => [['time' => 0, 'value' => '0deg'], ['time' => 600, 'value' => '45deg']]];
$spin = libraryRequest('saveEditorAnimation', [], ['_token' => 'test-csrf','definition' => json_encode($second)], 201)['animation'];
$list = libraryRequest('editorAnimations', [], [], 200, 'GET');
check(count($list['animations']) === 2 && $list['animations'][0]['name'] === 'Library fade', 'Selector endpoint lists all shared animations by name');
$loaded = libraryRequest('editorAnimations', ['id' => $fade['id']], [], 200, 'GET');
check(json_encode($loaded['animation']['definition']) === json_encode($first), 'Reopening a library animation preserves its keyframes');
libraryRequest('editorAnimations', ['id' => 99999], [], 404, 'GET');
libraryRequest('saveEditorAnimation', ['id' => $fade['id']], $libraryPost + ['lock_version' => '99'], 409);

$libraryOwner = request('page', ['_token' => 'test-csrf','name' => 'Shared animation page'], 201);
$libraryParams = ['type' => 'page','id' => (string)$libraryOwner['id']];
$bound = $element('p','Bound animated element');
$bound['_quill']->animations = [['animation_id' => $fade['id'],'trigger' => 'load'], ['animation_id' => $spin['id'],'trigger' => 'load']];
$clicked = $element('p','Click animation'); $clicked['_quill']->animations = [['animation_id' => $fade['id'],'trigger' => 'click']];
$missing = $element('p','Missing animation remains visible'); $missing['_quill']->animations = [['animation_id' => 99999,'trigger' => 'load']];
$libraryDocument = ['schema_version' => 1,'sections' => [['name' => 'main','blocks' => [$element('section','',[$bound,$clicked,$missing])]]]];
$librarySaved = documentRequest($libraryParams, ['_token' => 'test-csrf','lock_version' => '1','mode' => 'publish','document' => json_encode($libraryDocument)], 200);
$libraryPage = new QuillPage(); $libraryPage->selectByID($libraryOwner['id']);
$rendered = (new QuillToUrpcConverter())->convert(new WorkingQuillContentSource($libraryPage));
check(str_contains($rendered->scopedCss, 'animation-duration:1200ms,600ms') && str_contains($rendered->scopedCss, 'animation-name:quill-kf-'), 'Multiple active animations compose into one CSS animation list with their own timings');
$renderedChildren = array_values($rendered->document->getBlocks('main')[0]->getBlockChildren());
check($renderedChildren[2]->getBlockContent() === 'Missing animation remains visible' && !str_contains($renderedChildren[2]->getBlockAttributes()['class'] ?? '', 'quill-animation-'), 'Unknown database IDs are skipped without failing page rendering');
$libraryTransport = (new Quill\services\editor\EditorDocumentLoader())->load($libraryPage)['document'];
check($libraryTransport->sections[0]->blocks[0]->children[0]->_quill->animations[0]->animation_id === $fade['id'], 'Element transport retains database references for the active animation list');
$rawBound = $pdo->query("SELECT block_json FROM quill_block WHERE block_json LIKE '%Bound animated element%' ORDER BY id DESC LIMIT 1")->fetchColumn();
check(str_contains($rawBound, 'animation_id') && !str_contains($rawBound, 'keyframes') && !str_contains($rawBound, 'Library fade') && !str_contains($rawBound, '"tracks"'), 'Blocks contain database IDs and triggers only, never library keyframe definitions');

$libraryArchive = (new Quill\services\editor\ContentArchive())->capture(new WorkingQuillContentSource($libraryPage));
check(str_contains((new QuillToUrpcConverter())->convert(new Quill\services\editor\ArchivedContentSource($libraryArchive, $libraryArchive->root))->scopedCss, 'rotate:45deg'), 'Archived/template sources retain shared animation ID resolution');
$replacement = $first; $replacement['name'] = 'Fade replaced'; $replacement['tracks']->opacity[1]['value'] = '0.7';
$replaced = libraryRequest('saveEditorAnimation', ['id' => $fade['id']], ['_token' => 'test-csrf','lock_version' => '1','definition' => json_encode($replacement)], 200)['animation'];
check($replaced['id'] === $fade['id'] && $replaced['version'] === 2 && $replaced['admin_id'] === 1 && $replaced['created_at'] === $fade['created_at'], 'Replace keeps shared identity, original creator and creation date, with optimistic versioning');
$publishedLibraryPage = new QuillPageSnapshot(); $publishedLibraryPage->selectByID($librarySaved['snapshot_id']);
check(str_contains((new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($publishedLibraryPage))->scopedCss, 'opacity:0.7'), 'Published references resolve the current shared animation definition');
check($pdo->query("SELECT block_json FROM quill_block WHERE block_json LIKE '%Bound animated element%' ORDER BY id DESC LIMIT 1")->fetchColumn() === $rawBound, 'Replacing a shared resource does not modify referencing block JSON');
$copied = libraryRequest('saveEditorAnimation', [], ['_token' => 'test-csrf','definition' => json_encode($replacement)], 201)['animation'];
check($copied['id'] !== $fade['id'] && $copied['definition']->tracks->opacity[1]->value === '0.7', 'Save as new creates an independent database resource');

$component = (new Quill\services\editor\EditorComponentLibrary())->create('Animated component', null, json_decode(json_encode(['schema_version'=>1,'sections'=>[['name'=>'main','blocks'=>[$element('p','Component content')]]]])),1);
$instance = ['descriptor'=>['name'=>'quill-component','props'=>(object)[],'renderer'=>''],'attributes'=>(object)[],'content'=>'','children'=>[],
    '_quill'=>['component'=>['id'=>$component['id'],'instanceId'=>'qc-animation-test'],'animations'=>[['animation_id'=>$fade['id'],'trigger'=>'hover']]]];
$componentDocument = ['schema_version'=>1,'sections'=>[['name'=>'main','blocks'=>[$instance]]]];
$componentOwner = request('page',['_token'=>'test-csrf','name'=>'Animated instance'],201);
documentRequest(['type'=>'page','id'=>(string)$componentOwner['id']],['_token'=>'test-csrf','lock_version'=>'1','document'=>json_encode($componentDocument)],200);
$instancePage = new QuillPage(); $instancePage->selectByID($componentOwner['id']);
check(str_contains((new QuillToUrpcConverter())->convert(new WorkingQuillContentSource($instancePage))->scopedCss, ':hover{'), 'Shared component instances accept independent database animation references');
check((new Quill\services\editor\EditorDocumentLoader())->load($instancePage)['document']->sections[0]->blocks[0]->_quill->animations[0]->animation_id === $fade['id'], 'Component instance references round-trip without modifying component definitions');

libraryRequest('deleteEditorAnimation', ['id'=>$fade['id']], ['_token'=>'wrong','lock_version'=>'2'],403);
libraryRequest('deleteEditorAnimation', ['id'=>$fade['id']], ['_token'=>'test-csrf','lock_version'=>'1'],409);
libraryRequest('deleteEditorAnimation', ['id'=>$fade['id']], ['_token'=>'test-csrf','lock_version'=>'2'],200);
libraryRequest('editorAnimations', ['id'=>$fade['id']], [],404,'GET');
$afterDelete = (new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($publishedLibraryPage));
check(!str_contains($afterDelete->scopedCss,'opacity:0.7') && str_contains($afterDelete->scopedCss,'rotate:45deg'), 'Deleting one resource skips it while the other active animation still renders');
libraryRequest('deleteEditorAnimation', ['id'=>$spin['id']], ['_token'=>'test-csrf','lock_version'=>'1'],200);
$allMissing = (new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($publishedLibraryPage));
check(!str_contains($allMissing->scopedCss,'@keyframes') && $allMissing->document->getBlocks('main')[0]->getBlockChildren()[0]->getBlockContent() === 'Bound animated element', 'Pages remain renderable when every referenced animation is missing');
foreach ([ [['animation_id'=>0,'trigger'=>'load']], [['animation_id'=>1,'trigger'=>'unknown']], [['animation_id'=>1,'trigger'=>'load','tracks'=>[]]], [['animation_id'=>1,'trigger'=>'load'],['animation_id'=>1,'trigger'=>'hover']] ] as $invalidBindings) {
    try { AnimationLibrary::validateBindings(json_decode(json_encode($invalidBindings))); check(false,'Invalid animation bindings must reject'); } catch (InvalidArgumentException) { check(true,'Invalid animation bindings reject'); }
}
echo "Passed animation library CRUD, sharing, reference persistence, composition and missing-resource checks; $count total checks.\n";
ob_end_flush();
