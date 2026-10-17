<?php
// Real authenticated endpoints, SQLite transactions, converter and editor bootstrap.
ob_start();
require __DIR__ . '/editor_save.php';

use Quill\models\QuillComponent;
use Quill\services\editor\EditorComponentLibrary;

$root = $element('section', '', [$element('button', 'Open details'), $element('p', 'Details')]);
$root['children'][0]['_quill']->editor = (object) ['onClick' => 'qa-component'];
$root['children'][1]['_quill']->editor = (object) ['actionTargetId' => 'qt-component'];
$componentDocument = (object) [
    'schema_version' => 1,
    'sections' => [(object) ['name' => 'main', 'blocks' => [$root]]],
    'assets' => (object) ['styles' => ['component.css'], 'scripts' => ['component.js']],
    'settings' => (object) ['breakpoints' => (object) ['tablet' => 1024, 'mobile' => 767], 'actions' => [(object) [
        'id' => 'qa-component', 'name' => 'Open component details', 'trigger' => 'click', 'steps' => [(object) [
            'id' => 'qs-component', 'target' => (object) ['type' => 'element', 'value' => 'qt-component'],
            'condition' => null, 'operation' => (object) ['type' => 'show'],
        ]],
    ]]],
];
$componentPost = ['_token' => 'test-csrf', 'name' => 'Reusable details', 'description' => 'A saved subtree', 'document' => json_encode($componentDocument)];
request('createEditorComponent', array_replace($componentPost, ['_token' => 'wrong']), 403);
request('createEditorComponent', $componentPost, 405, 'GET');
request('createEditorComponent', array_replace($componentPost, ['admin_id' => 999]), 422);
request('createEditorComponent', array_replace($componentPost, ['name' => '']), 422);
$createdComponent = request('createEditorComponent', $componentPost, 201)['component'];
$model = new QuillComponent(); $model->selectByID($createdComponent['id']);
check($model->name === 'Reusable details' && $model->description === 'A saved subtree' && $model->lock_version === 2 && $model->admin_id === 1, 'Component creation persists metadata and version using authenticated author');
$expandedComponent = (new EditorComponentLibrary())->document($model);
check(count($expandedComponent->sections) === 1 && count($expandedComponent->sections[0]->blocks) === 1 && count($expandedComponent->sections[0]->blocks[0]->children) === 2, 'Saved component restores the selected root and all its children');
check($expandedComponent->sections[0]->blocks[0]->children[0]->_quill->editor->onClick === 'qa-component'
    && $expandedComponent->settings->actions[0]->steps[0]->target->value === 'qt-component', 'Component round-trip retains actions and target metadata');
check($expandedComponent->assets->styles === ['component.css'] && $expandedComponent->assets->scripts === ['component.js'], 'Component dependency assets survive persistence');
check(!isset($expandedComponent->sections[0]->blocks[0]->children[0]->attributes->{'data-quill-action'}), 'Editor component transport strips generated runtime action bindings');

function componentDetail(int $id, int $expected): array {
    global $controller;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $response = $controller->editorComponent(['id' => (string) $id]);
    $status = new ReflectionProperty(response\Response::class, 'statusCode');
    check($status->getValue($response) === $expected, 'Component detail expected ' . $expected);
    return (new ReflectionProperty(response\Response::class, 'payload'))->getValue($response);
}
$detail = componentDetail($createdComponent['id'], 200);
check($detail['document']->sections[0]->blocks[0]->children[1]->content === 'Details', 'Authenticated component endpoint returns insertable nested editor blocks');
componentDetail(99999, 404);
$emptyComponent = request('component', ['_token' => 'test-csrf', 'name' => 'Empty reusable block'], 201);
componentDetail($emptyComponent['id'], 422);
(new QuillComponent())->table('quill_component')->where(['id' => $emptyComponent['id']])->update(['deleted_at' => date('Y-m-d H:i:s')]);
componentDetail($emptyComponent['id'], 404);
check(!in_array($emptyComponent['id'], array_column((new EditorComponentLibrary())->all(), 'id'), true), 'Deleted components are excluded from the editor library');

$beforeComponents = (int) $pdo->query('SELECT COUNT(*) FROM quill_component')->fetchColumn();
$beforeBlocks = (int) $pdo->query('SELECT COUNT(*) FROM quill_block')->fetchColumn();
$invalid = clone $componentDocument;
$invalid->sections = [(object) ['name' => 'main', 'blocks' => []]];
request('createEditorComponent', array_replace($componentPost, ['document' => json_encode($invalid)]), 422);
$invalid = json_decode(json_encode($componentDocument));
$invalid->sections[0]->blocks[0]->children[0]->descriptor->name = 'invalid tag';
request('createEditorComponent', array_replace($componentPost, ['document' => json_encode($invalid)]), 422);
check((int) $pdo->query('SELECT COUNT(*) FROM quill_component')->fetchColumn() === $beforeComponents
    && (int) $pdo->query('SELECT COUNT(*) FROM quill_block')->fetchColumn() === $beforeBlocks, 'Validation failures leave no empty components or orphan blocks');
$pdo->exec("CREATE TRIGGER fail_component_block BEFORE INSERT ON quill_block BEGIN SELECT RAISE(ABORT, 'component rollback'); END");
try {
    (new EditorComponentLibrary())->create('Must roll back', null, json_decode($componentPost['document']), 1);
    throw new RuntimeException('Expected database failure.');
} catch (model\exceptions\ModelCRUDException $expected) {
    check((int) $pdo->query('SELECT COUNT(*) FROM quill_component')->fetchColumn() === $beforeComponents, 'Block write failure rolls back component creation atomically');
}
$pdo->exec('DROP TRIGGER fail_component_block');

$assetPage = request('page', ['_token' => 'test-csrf', 'name' => 'Component host'], 201);
$expandedComponent->settings->title = 'Component host';
$assetPost = ['_token' => 'test-csrf', 'mode' => 'publish', 'lock_version' => '1', 'document' => json_encode($expandedComponent)];
documentRequest(['type' => 'page', 'id' => (string) $assetPage['id']], $assetPost, 200);
$assetOwner = new Quill\models\QuillPage(); $assetOwner->selectByID($assetPage['id']);
check($assetOwner->getDocument()->assets->styles === ['component.css'], 'Page save retains newly inserted component dependencies');
$previous = libxml_use_internal_errors(true);
$componentView = $viewController->editor(['type' => 'page', 'id' => (string) $assetPage['id']]);
libxml_clear_errors(); libxml_use_internal_errors($previous);
check(in_array($createdComponent['id'], array_column($componentView->bundle['editorComponents'], 'id'), true), 'Editor Components section bootstraps records from QuillComponent');
$suiteOutput = ob_get_clean();
echo $suiteOutput;
echo "Passed editor component library checks; $count total persistence checks.\n";
