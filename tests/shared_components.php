<?php
ob_start();
require __DIR__ . '/editor_components.php';

use Quill\models\QuillComponent;
use Quill\models\QuillPage;
use Quill\models\QuillPageSnapshot;
use Quill\services\editor\EditorComponentLibrary;
use Quill\services\editor\EditorDocumentLoader;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\quillToUrpc\WorkingQuillContentSource;
use Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource;

function sharedNode(int $id, string $instance): stdClass {
    return (object) ['descriptor' => (object) ['name' => 'quill-component', 'props' => new stdClass(), 'renderer' => ''],
        'attributes' => new stdClass(), 'content' => '', 'children' => [],
        '_quill' => (object) ['component' => (object) ['id' => $id, 'name' => 'Client name is not authoritative', 'version' => 1, 'instanceId' => $instance]]];
}
function sharedDocument(array $blocks, string $title = 'Shared host'): stdClass {
    return (object) ['schema_version' => 1, 'sections' => [(object) ['name' => 'main', 'blocks' => $blocks]],
        'settings' => (object) ['title' => $title], 'assets' => (object) ['styles' => [], 'scripts' => []]];
}
function saveSharedPage(int $id, stdClass $document, int $version, string $mode = 'draft', int $expected = 200): array {
    return documentRequest(['type' => 'page', 'id' => (string) $id], ['_token' => 'test-csrf', 'mode' => $mode, 'lock_version' => (string) $version,
        'document' => json_encode($document, JSON_THROW_ON_ERROR)], $expected);
}
function loadedComponent(int $id): QuillComponent { $component = new QuillComponent(); $component->selectByID($id); return $component; }
function loadedPage(int $id): QuillPage { $page = new QuillPage(); $page->selectByID($id); return $page; }

$sharedId = $createdComponent['id'];
$firstHost = request('page', ['_token' => 'test-csrf', 'name' => 'Shared host'], 201);
$secondHost = request('page', ['_token' => 'test-csrf', 'name' => 'Other shared host'], 201);
$firstDocument = sharedDocument([sharedNode($sharedId, 'qc-first'), sharedNode($sharedId, 'qc-second')]);
$beforeBlocks = (int) $pdo->query('SELECT COUNT(*) FROM quill_block')->fetchColumn();
saveSharedPage($firstHost['id'], $firstDocument, 1);
check((int) $pdo->query('SELECT COUNT(*) FROM quill_block')->fetchColumn() === $beforeBlocks + 2, 'Saving two instances stores two references without copying the component tree');
$page = loadedPage($firstHost['id']);
$ref = (new Quill\models\QuillBlock())->newQuery()->where(['id' => $page->getDocument()->sections[0]->blocks[0]])->firstModel()->getDocument();
check($ref->kind === 'component' && $ref->component_id === $sharedId && !isset($ref->children, $ref->descriptor), 'Working page stores a native Quill component reference and stable instance identity');
saveSharedPage($secondHost['id'], sharedDocument([sharedNode($sharedId, 'qc-third')], 'Other shared host'), 1);
$firstLoaded = (new EditorDocumentLoader())->load($page)['document'];
check($firstLoaded->sections[0]->blocks[0]->_quill->component->id === $sharedId
    && $firstLoaded->sections[0]->blocks[0]->_quill->component->name === 'Reusable details'
    && count($firstLoaded->sections[0]->blocks[0]->children[0]->children) === 2, 'Editor reconstructs one component unit around its authoritative expanded preview');
check(!isset($firstLoaded->settings->actions) && $firstLoaded->assets->styles === [], 'Shared actions and assets are not flattened into page-owned settings');
$rendered = (new QuillToUrpcConverter())->convert(new WorkingQuillContentSource($page));
$roots = $rendered->document->getBlocks('main');
$firstAction = $roots[0]->getBlockChildren()[0]->getBlockAttributes()['data-quill-action'];
$secondAction = $roots[1]->getBlockChildren()[0]->getBlockAttributes()['data-quill-action'];
$firstTarget = $roots[0]->getBlockChildren()[1]->getBlockAttributes()['data-quill-target'];
$secondTarget = $roots[1]->getBlockChildren()[1]->getBlockAttributes()['data-quill-target'];
check($firstAction !== $secondAction && $firstTarget !== $secondTarget, 'Shared instances receive separate runtime action and target identities');
$byId = array_column($rendered->actions, null, 'id');
check($byId[$firstAction]->steps[0]->target->value === $firstTarget && $byId[$secondAction]->steps[0]->target->value === $secondTarget, 'Each shared action resolves targets inside its own instance');
check($roots[0]->getBlockDescriptor()->getBlockName() === 'section', 'Editor-only component wrappers never enter public output');

$master = loadedComponent($sharedId);
$masterDocument = (new EditorComponentLibrary())->document($master);
$masterDocument->sections[0]->blocks[0]->children[0]->content = 'Updated at source';
documentRequest(['type' => 'component', 'id' => (string) $sharedId], ['_token' => 'test-csrf', 'mode' => 'draft', 'lock_version' => (string) $master->lock_version,
    'document' => json_encode($masterDocument)], 200);
foreach ([$firstHost, $secondHost] as $host) {
    $loaded = (new EditorDocumentLoader())->load(loadedPage($host['id']))['document'];
    check($loaded->sections[0]->blocks[0]->children[0]->children[0]->content === 'Updated at source', 'All page editors load the current component source without resaving the page');
}
$forged = (new EditorDocumentLoader())->load(loadedPage($firstHost['id']))['document'];
$forged->sections[0]->blocks[0]->children[0]->children[0]->content = 'Page must not edit the master';
$forged->sections[0]->blocks[0]->attributes->style = 'color:red';
saveSharedPage($firstHost['id'], $forged, 2);
check((new EditorComponentLibrary())->document(loadedComponent($sharedId))->sections[0]->blocks[0]->children[0]->content === 'Updated at source', 'A forged or stale page preview cannot overwrite component content');
$page = loadedPage($firstHost['id']);
$published = saveSharedPage($firstHost['id'], (new EditorDocumentLoader())->load($page)['document'], 3, 'publish');
$snapshot = new QuillPageSnapshot(); $snapshot->selectByID($published['snapshot_id']);
$snapshotResult = (new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($snapshot));
check($snapshotResult->document->getBlocks('main')[0]->getBlockChildren()[0]->getBlockContent() === 'Updated at source', 'Publish captures referenced components with exact snapshot manifests');
check((int) $pdo->query('SELECT COUNT(*) FROM quill_page_snapshot_component WHERE page_snapshot_id=' . $published['snapshot_id'])->fetchColumn() === 1, 'Repeated instances share one component manifest entry per page snapshot');

$nestedRoot = $element('div', '', []);
$nestedRoot['children'] = [sharedNode($sharedId, 'qc-nested')];
$nested = request('createEditorComponent', ['_token' => 'test-csrf', 'name' => 'Nested shared component', 'document' => json_encode(sharedDocument([$nestedRoot], 'Nested shared component'))], 201)['component'];
$nestedHost = request('page', ['_token' => 'test-csrf', 'name' => 'Nested host'], 201);
$nestedPublished = saveSharedPage($nestedHost['id'], sharedDocument([sharedNode($nested['id'], 'qc-outer')], 'Nested host'), 1, 'publish');
$nestedSnapshot = new QuillPageSnapshot(); $nestedSnapshot->selectByID($nestedPublished['snapshot_id']);
check((new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($nestedSnapshot))->document->getBlocks('main')[0]->getBlockChildren()[0]->getBlockDescriptor()->getBlockName() === 'section', 'Nested shared components publish through recursively captured component manifests');
$nestedPreview = (new EditorDocumentLoader())->load(loadedPage($nestedHost['id']))['document'];
check($nestedPreview->sections[0]->blocks[0]->children[0]->children[0]->_quill->component->id === $sharedId, 'Nested references retain their source identity in editor previews');

$master = loadedComponent($sharedId); $cycleDocument = (new EditorComponentLibrary())->document($master);
$cycleDocument->sections[0]->blocks[0]->children[] = sharedNode($sharedId, 'qc-self');
$cyclePost = ['_token' => 'test-csrf', 'mode' => 'draft', 'lock_version' => (string) $master->lock_version, 'document' => json_encode($cycleDocument)];
documentRequest(['type' => 'component', 'id' => (string) $sharedId], $cyclePost, 422);
$cycleDocument->sections[0]->blocks[0]->children[count($cycleDocument->sections[0]->blocks[0]->children) - 1] = sharedNode($nested['id'], 'qc-indirect');
$cyclePost['document'] = json_encode($cycleDocument);
documentRequest(['type' => 'component', 'id' => (string) $sharedId], $cyclePost, 422);
check(loadedComponent($sharedId)->lock_version === $master->lock_version, 'Direct and indirect component cycles are rejected before any source write');
$masterDocument = (new EditorComponentLibrary())->document($master);
$masterDocument->sections[0]->blocks[0]->children[0]->content = 'Newer working source';
$cyclePost['document'] = json_encode($masterDocument);
documentRequest(['type' => 'component', 'id' => (string) $sharedId], $cyclePost, 200);
check((new QuillToUrpcConverter())->convert(new WorkingQuillContentSource(loadedPage($firstHost['id'])))->document->getBlocks('main')[0]->getBlockChildren()[0]->getBlockContent() === 'Newer working source', 'Working pages immediately resolve later component edits');
check((new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($snapshot))->document->getBlocks('main')[0]->getBlockChildren()[0]->getBlockContent() === 'Updated at source', 'Published snapshots retain explicit publication history');
$externalHost = request('page', ['_token' => 'test-csrf', 'name' => 'External target host'], 201);
$externalRef = sharedNode($sharedId, 'qc-external');
$externalRef->_quill->component->externalTargets = (object) ['qt-before-conversion' => 'qt-component'];
$externalButton = $element('button', 'Outside trigger');
$externalButton['_quill']->editor = (object) ['onClick' => 'qa-outside'];
$externalDocument = sharedDocument([$externalButton, $externalRef], 'External target host');
$externalDocument->settings->actions = [(object) ['id' => 'qa-outside', 'name' => 'Outside action', 'trigger' => 'click', 'steps' => [(object) [
    'id' => 'qs-outside', 'target' => (object) ['type' => 'element', 'value' => 'qt-before-conversion'], 'condition' => null, 'operation' => (object) ['type' => 'show'],
]]]];
saveSharedPage($externalHost['id'], $externalDocument, 1);
$externalResult = (new QuillToUrpcConverter())->convert(new WorkingQuillContentSource(loadedPage($externalHost['id'])));
$externalTarget = $externalResult->document->getBlocks('main')[1]->getBlockChildren()[1]->getBlockAttributes()['data-quill-target'];
$externalActions = array_column($externalResult->actions, null, 'id');
check($externalActions['qa-outside']->steps[0]->target->value === $externalTarget, 'Existing page actions keep targeting an element after it is converted to a shared component');
$externalPreview = (new EditorDocumentLoader())->load(loadedPage($externalHost['id']))['document'];
check($externalPreview->sections[0]->blocks[1]->_quill->component->externalTargets->{'qt-before-conversion'} === 'qt-component', 'External target aliases survive editor reloads');
$badIdentity = sharedDocument([sharedNode($sharedId, 'invalid')]);
saveSharedPage($secondHost['id'], $badIdentity, 2, 'draft', 422);
$suiteOutput = ob_get_clean(); echo $suiteOutput;
echo "Passed shared component persistence, synchronization and snapshot checks; $count total checks.\n";
