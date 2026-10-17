<?php
// Uses the actual authenticated controller, models and an isolated SQLite database.
ob_start();
require __DIR__ . '/save.php';
$saveSuiteOutput = ob_get_clean();

use Quill\models\QuillPage;
use Quill\models\QuillPageSnapshot;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource;
use Quill\services\conversion\quillToUrpc\WorkingQuillContentSource;

$pdo->exec('ALTER TABLE quill_page ADD COLUMN published_snapshot_id INTEGER');
$pdo->exec('ALTER TABLE quill_page ADD COLUMN source_key TEXT');
$pdo->exec('ALTER TABLE quill_component ADD COLUMN published_snapshot_id INTEGER');
foreach ([
    'quill_page_snapshot' => 'page_id INTEGER, revision INTEGER, name TEXT, description TEXT, source_type TEXT, source_key TEXT, page_json TEXT',
    'quill_component_snapshot' => 'component_id INTEGER, revision INTEGER, name TEXT, description TEXT, component_json TEXT',
    'quill_block_snapshot' => 'block_id INTEGER, revision INTEGER, block_json TEXT',
    'quill_page_snapshot_block' => 'page_snapshot_id INTEGER, block_id INTEGER, block_snapshot_id INTEGER',
    'quill_component_snapshot_block' => 'component_snapshot_id INTEGER, block_id INTEGER, block_snapshot_id INTEGER',
    'quill_page_snapshot_component' => 'page_snapshot_id INTEGER, component_id INTEGER, component_snapshot_id INTEGER',
    'quill_component_snapshot_component' => 'owner_snapshot_id INTEGER, component_id INTEGER, referenced_snapshot_id INTEGER',
] as $table => $columns) $pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, $columns)");

function documentRequest(array $params, array $post, int $expected): array
{
    global $controller;
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $response = $controller->saveDocument($params);
    ob_start();
    $response->send();
    $wire = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    check($wire['status'] === $expected, 'Document response expected ' . $expected . ': ' . json_encode($wire));
    return $wire['data'];
}
$owner = request('page', ['_token' => 'test-csrf', 'name' => 'Editor persistence'], 201);
$params = ['type' => 'page', 'id' => (string) $owner['id']];
(new database\DatabaseManager())->table('quill_page')->where(['id' => $owner['id']])->update([
    'page_json' => '{"schema_version":1,"sections":[],"assets":{"styles":["site.css"],"scripts":[]},"settings":{"seo":"Keep metadata"}}',
]);
$element = static fn (string $name, string $content, array $children = []): array => [
    'descriptor' => ['name' => $name, 'props' => (object) ['object' => new stdClass(), 'list' => []], 'renderer' => ''],
    'attributes' => (object) ['style' => 'display: flex; color: blue;', 'class' => 'hero quill-scope-' . str_repeat('a', 64)], 'content' => $content, 'children' => $children,
    '_quill' => (object) ['definition_key' => 'Quill:element', 'styles' => (object) ['base' => (object) ['color' => 'red']],
        'editor' => (object) ['label' => 'Original label', 'locked' => false]],
];
$document = ['schema_version' => 1, 'sections' => [
    ['name' => 'main', 'blocks' => [$element('section', '', [$element('p', 'Draft text')])]],
    ['name' => 'footer', 'blocks' => [$element('p', 'Footer')]],
]];
$post = ['_token' => 'test-csrf', 'document' => json_encode($document, JSON_THROW_ON_ERROR), 'lock_version' => '1'];
documentRequest($params, array_replace($post, ['_token' => 'forged']), 403);
$invalidVersion = documentRequest($params, array_replace($post, ['lock_version' => 'undefined']), 422);
check(str_contains($invalidVersion['message'], 'lock_version'), 'Invalid version identifies the failing request field');
$draft = documentRequest($params, $post, 200);
check($draft['mode'] === 'draft' && $draft['lock_version'] === 2 && $draft['snapshot_id'] === null, 'Draft is default and returns updated lock version');
$page = new QuillPage();
$page->selectByID($owner['id']);
check($page->status === 'draft' && $page->published_snapshot_id === null, 'Draft does not publish');
check($page->getDocument()->settings->seo === 'Keep metadata' && $page->getDocument()->assets->styles === ['site.css'], 'Page settings and assets are retained');
$converted = (new QuillToUrpcConverter())->convert(new WorkingQuillContentSource($page));
check($converted->document->getBlocks('main')[0]->getBlockChildren()[0]->getBlockContent() === 'Draft text', 'Database references map back to complete hierarchy');
$saved = $pdo->query('SELECT block_json FROM quill_block ORDER BY id DESC LIMIT 1')->fetchColumn();
$saved = json_decode($saved);
check($saved->definition_key === 'Quill:element' && $saved->editor->label === 'Original label' && $saved->styles->base->color === 'red', 'Source metadata retained');
check($saved->attributes->class === 'hero', 'Generated preview scope classes are not persisted or accumulated');
check($saved->descriptor->props->object instanceof stdClass && is_array($saved->descriptor->props->list), 'Persistence preserves nested JSON types');
$countBefore = (int) $pdo->query('SELECT COUNT(*) FROM quill_block')->fetchColumn();
documentRequest($params, $post, 409);
check((int) $pdo->query('SELECT COUNT(*) FROM quill_block')->fetchColumn() === $countBefore, 'Stale save does not insert blocks');
$published = documentRequest($params, array_replace($post, ['mode' => 'publish', 'lock_version' => '2']), 200);
check($published['snapshot_id'] !== null && $published['lock_version'] === 3, 'Publish creates exact snapshot');
$snapshot = new QuillPageSnapshot();
$snapshot->selectByID($published['snapshot_id']);
$publishedResult = (new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($snapshot));
check($publishedResult->document->getBlocks('main')[0]->getBlockChildren()[0]->getBlockContent() === 'Draft text', 'Published manifest resolves nested blocks exactly');
$document['sections'][0]['blocks'][0]['children'][0]['content'] = 'Changed draft';
$nextDraft = array_replace($post, ['document' => json_encode($document, JSON_THROW_ON_ERROR), 'lock_version' => '3']);
documentRequest($params, $nextDraft, 200);
check((new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($snapshot))->document->getBlocks('main')[0]->getBlockChildren()[0]->getBlockContent() === 'Draft text', 'Later drafts cannot mutate published blocks');
$invalid = $document;
$invalid['sections'][0]['blocks'][0]['descriptor']['name'] = 'invalid tag';
documentRequest($params, array_replace($nextDraft, ['document' => json_encode($invalid), 'lock_version' => '4']), 422);
$component = request('component', ['_token' => 'test-csrf', 'name' => 'Editor component'], 201);
$componentPublish = documentRequest(['type' => 'component', 'id' => $component['id']], array_replace($post, ['mode' => 'publish']), 200);
check($componentPublish['snapshot_id'] !== null, 'Component can publish with its own manifest');
$beforeRollback = (int) $pdo->query('SELECT COUNT(*) FROM quill_block')->fetchColumn();
$pdo->exec("CREATE TRIGGER fail_editor_snapshot BEFORE INSERT ON quill_block_snapshot BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
// Call the service directly so the expected database failure is not logged by the HTTP controller.
$page->selectByID($owner['id']);
try {
    (new Quill\services\editor\EditorDocumentSaver())->save($page, json_decode($nextDraft['document']), 4, 'publish', 1);
    throw new RuntimeException('Expected failed snapshot insertion.');
} catch (model\exceptions\ModelCRUDException $expected) {
    check((int) $pdo->query('SELECT COUNT(*) FROM quill_block')->fetchColumn() === $beforeRollback, 'Failed publish rolls back block inserts');
    $page->selectByID($owner['id']);
    check($page->lock_version === 4, 'Failed publish rolls back optimistic lock change');
}
$pdo->exec('DROP TRIGGER fail_editor_snapshot');
$settingsOwner = request('page', ['_token' => 'test-csrf', 'name' => 'Settings test'], 201);
$settingsParams = ['type' => 'page', 'id' => (string) $settingsOwner['id']];
$settingsDocument = $document;
$settingsDocument['settings'] = (object) ['title' => 'Updated page title', 'description' => 'Page description', 'styles' => (object) ['padding' => '24px', 'margin' => '0px', 'background-color' => '#123456']];
$settingsPost = array_replace($post, ['document' => json_encode($settingsDocument), 'mode' => 'publish']);
$settingsSaved = documentRequest($settingsParams, $settingsPost, 200);
$settingsPage = new QuillPage(); $settingsPage->selectByID($settingsOwner['id']);
check($settingsPage->name === 'Updated page title' && $settingsPage->description === 'Page description', 'General settings update dashboard metadata atomically');
check($settingsPage->getDocument()->settings->styles->padding === '24px', 'Page styling persists in document settings');
$settingsSnapshot = new QuillPageSnapshot(); $settingsSnapshot->selectByID($settingsSaved['snapshot_id']);
check($settingsSnapshot->name === 'Updated page title' && $settingsSnapshot->description === 'Page description', 'Published snapshot captures current General settings');
$settingsConversion = (new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($settingsSnapshot));
check(str_contains($settingsConversion->scopedCss, 'body{padding:24px;margin:0px;background-color:#123456;}'), 'Published page styling is included in renderable CSS');
$settingsErrors = libxml_use_internal_errors(true);
$settingsView = $viewController->editor(['type' => 'page', 'id' => (string) $settingsOwner['id']]);
libxml_clear_errors();
libxml_use_internal_errors($settingsErrors);
check(json_decode($settingsView->bundle['editorDocumentJson'])->settings->title === 'Updated page title', 'Reopening the editor restores page settings');
$badSettings = $settingsDocument; $badSettings['settings'] = (object) ['title' => '', 'styles' => (object) ['padding' => '2px']];
documentRequest($settingsParams, array_replace($settingsPost, ['document' => json_encode($badSettings), 'lock_version' => '2']), 422);
$badSettings['settings'] = (object) ['styles' => (object) ['position' => 'fixed']];
documentRequest($settingsParams, array_replace($settingsPost, ['document' => json_encode($badSettings), 'lock_version' => '2']), 422);
$badSettings['settings'] = (object) ['styles' => (object) ['background' => 'red;}body{display:none']];
documentRequest($settingsParams, array_replace($settingsPost, ['document' => json_encode($badSettings), 'lock_version' => '2']), 422);
$settingsPage->selectByID($settingsOwner['id']);
check($settingsPage->lock_version === 2 && $settingsPage->name === 'Updated page title', 'Invalid page settings do not mutate the saved document');
$preferencesOwner = request('page', ['_token' => 'test-csrf', 'name' => 'Default font and palette'], 201);
$preferencesParams = ['type' => 'page', 'id' => (string) $preferencesOwner['id']];
$preferencesBlock = $element('p', 'Default font text'); $preferencesBlock['attributes'] = new stdClass();
$preferencesDocument = ['schema_version' => 1, 'sections' => [['name' => 'main', 'blocks' => [$preferencesBlock]]], 'settings' => (object) [
    'styles' => (object) ['font-family' => '"Inter", sans-serif', 'font-weight' => '600', 'font-style' => 'italic'],
    'color_palette' => [(object) ['id' => 'qp-brand', 'name' => 'Brand', 'value' => '#123456'], (object) ['id' => 'qp-alpha', 'name' => 'Transparent', 'value' => 'rgba(12, 34, 56, 0.25)'], (object) ['id' => 'qp-gradient', 'name' => 'Hero', 'value' => 'linear-gradient(90deg, red 0%, blue 100%)']],
]];
$preferencesPost = array_replace($post, ['document' => json_encode($preferencesDocument), 'mode' => 'publish']);
$preferencesSaved = documentRequest($preferencesParams, $preferencesPost, 200);
$preferencesPage = new QuillPage(); $preferencesPage->selectByID($preferencesOwner['id']);
check($preferencesPage->getDocument()->settings->styles->{'font-family'} === '"Inter", sans-serif' && count($preferencesPage->getDocument()->settings->color_palette) === 3, 'Default font and named palette persist with the page');
$preferencesSnapshot = new QuillPageSnapshot(); $preferencesSnapshot->selectByID($preferencesSaved['snapshot_id']);
$preferencesResult = (new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($preferencesSnapshot));
check(str_contains($preferencesResult->scopedCss, 'body{font-family:"Inter", sans-serif;font-weight:600;font-style:italic;}') && in_array('https://fonts.googleapis.com/css2?family=Inter:ital,wght@1,600&display=swap', $preferencesResult->assets['styles'], true), 'Published default font reaches body CSS and requests inherited Google font variants');
check($preferencesSnapshot->getDocument()->settings->color_palette[2]->value === 'linear-gradient(90deg, red 0%, blue 100%)', 'Snapshots retain alpha colors and saved gradients');
$preferencesErrors = libxml_use_internal_errors(true);
$preferencesView = $viewController->editor(['type' => 'page', 'id' => (string) $preferencesOwner['id']]); libxml_clear_errors(); libxml_use_internal_errors($preferencesErrors);
$reopenedPreferences = json_decode($preferencesView->bundle['editorDocumentJson'])->settings;
check($reopenedPreferences->styles->{'font-weight'} === '600' && $reopenedPreferences->color_palette[0]->name === 'Brand', 'Reopening restores the default font and palette');
foreach ([
    [(object) ['id' => 'qp-bad', 'name' => 'Bad', 'value' => 'url(https://example.com/image.png)']],
    [(object) ['id' => 'qp-same', 'name' => 'One', 'value' => 'red'], (object) ['id' => 'qp-same', 'name' => 'Two', 'value' => 'blue']],
    [(object) ['id' => 'qp-one', 'name' => 'Same', 'value' => 'red'], (object) ['id' => 'qp-two', 'name' => 'same', 'value' => 'blue']],
    [(object) ['id' => 'qp-bad', 'name' => 'Bad', 'value' => 'red;}body{display:none']],
    [(object) ['id' => 'qp-bad', 'name' => '', 'value' => '#fff']],
    (object) ['name' => 'Expected list'],
] as $badPalette) {
    $badPreferences = json_decode(json_encode($preferencesDocument)); $badPreferences->settings->color_palette = $badPalette;
    documentRequest($preferencesParams, array_replace($preferencesPost, ['document' => json_encode($badPreferences), 'lock_version' => '2']), 422);
}
$preferencesPage->selectByID($preferencesOwner['id']);
check($preferencesPage->lock_version === 2 && count($preferencesPage->getDocument()->settings->color_palette) === 3, 'Invalid palette submissions cannot mutate the saved page');
$responsiveDocument = $settingsDocument;
$responsiveDocument['settings']->breakpoints = (object) ['tablet' => 1024, 'mobile' => 767];
// Fixtures use attribute objects; replace the root explicitly for a normalized responsive block.
$responsiveDocument['sections'][0]['blocks'][0]['attributes'] = (object) ['class' => 'hero'];
$responsiveDocument['sections'][0]['blocks'][0]['_quill']->styles = (object) [
    'base' => (object) ['padding' => '12px'], 'desktop' => (object) ['padding' => '48px'],
    'tablet' => (object) ['padding' => '24px'], 'mobile' => (object) ['padding' => '8px'],
];
$responsiveSaved = documentRequest($settingsParams, array_replace($settingsPost, ['document' => json_encode($responsiveDocument), 'lock_version' => '2']), 200);
$responsiveSnapshot = new QuillPageSnapshot(); $responsiveSnapshot->selectByID($responsiveSaved['snapshot_id']);
$responsiveResult = (new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($responsiveSnapshot));
check(str_contains($responsiveResult->scopedCss, '@media (min-width: 1025px)') && str_contains($responsiveResult->scopedCss, '@media (max-width: 1024px)') && str_contains($responsiveResult->scopedCss, '@media (max-width: 767px)'), 'Saved responsive configuration renders Desktop, Tablet and Mobile CSS without external breakpoint setup');
$settingsErrors = libxml_use_internal_errors(true);
$responsiveView = $viewController->editor(['type' => 'page', 'id' => (string) $settingsOwner['id']]);
libxml_clear_errors(); libxml_use_internal_errors($settingsErrors);
$responsiveTransport = json_decode($responsiveView->bundle['editorDocumentJson']);
check($responsiveTransport->sections[0]->blocks[0]->_quill->styles->desktop->padding === '48px' && $responsiveTransport->settings->breakpoints->mobile === 767, 'Editor reload retains responsive metadata and breakpoints');
$actionOwner = request('page', ['_token' => 'test-csrf', 'name' => 'Actions page'], 201);
$actionParams = ['type' => 'page', 'id' => (string) $actionOwner['id']];
$actionDefinition = (object) ['id' => 'qa-test', 'name' => 'Toggle </script> panel', 'trigger' => 'click', 'steps' => [
    (object) ['id' => 'qs-style', 'target' => (object) ['type' => 'element', 'value' => 'qt-panel'], 'condition' => (object) ['type' => 'has-class', 'value' => 'hero'], 'operation' => (object) ['type' => 'set-style', 'property' => 'background-color', 'value' => '#2ecc71']],
    (object) ['id' => 'qs-class', 'target' => (object) ['type' => 'self'], 'condition' => null, 'operation' => (object) ['type' => 'toggle-class', 'value' => 'active']],
]];
$actionRoot = $element('section', '', [$element('button', 'Activate')]);
$actionRoot['_quill']->editor->actionTargetId = 'qt-panel';
$actionRoot['children'][0]['_quill']->editor->onClick = 'qa-test';
$actionDocument = ['schema_version' => 1, 'settings' => (object) ['actions' => [$actionDefinition]], 'sections' => [['name' => 'main', 'blocks' => [$actionRoot]]]];
$actionPost = array_replace($post, ['document' => json_encode($actionDocument), 'mode' => 'publish']);
$actionSaved = documentRequest($actionParams, $actionPost, 200);
$actionPage = new QuillPage(); $actionPage->selectByID($actionOwner['id']);
$actionSnapshot = new QuillPageSnapshot(); $actionSnapshot->selectByID($actionSaved['snapshot_id']);
$actionResult = (new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($actionSnapshot));
check(count($actionResult->actions) === 1 && $actionPage->getDocument()->settings->actions[0]->steps[0]->target->value === 'qt-panel', 'Named action catalog and optional conditions survive Save/Publish');
check($actionResult->document->getBlocks('main')[0]->getBlockAttributes()['data-quill-target'] === 'qt-panel' && $actionResult->document->getBlocks('main')[0]->getBlockChildren()[0]->getBlockAttributes()['data-quill-action'] === 'qa-test', 'URPC receives declarative action and stable target attributes');
$settingsErrors = libxml_use_internal_errors(true);
$actionView = $viewController->editor(['type' => 'page', 'id' => (string) $actionOwner['id']]);
libxml_clear_errors(); libxml_use_internal_errors($settingsErrors);
$actionTransport = json_decode($actionView->bundle['editorDocumentJson']);
check($actionTransport->sections[0]->blocks[0]->children[0]->_quill->editor->onClick === 'qa-test' && !isset($actionTransport->sections[0]->blocks[0]->children[0]->attributes->{'data-quill-action'}), 'Editor reopens action bindings from metadata without persisting generated runtime attributes');
$actionBlockCount = (int) $pdo->query('SELECT COUNT(*) FROM quill_block')->fetchColumn();
$badActionDocument = json_decode(json_encode($actionDocument));
$badActionDocument->settings->actions[0]->steps[0]->operation->type = 'execute-javascript';
documentRequest($actionParams, array_replace($actionPost, ['document' => json_encode($badActionDocument), 'lock_version' => '2']), 422);
$badActionDocument = json_decode(json_encode($actionDocument));
$badActionDocument->settings->actions = [];
documentRequest($actionParams, array_replace($actionPost, ['document' => json_encode($badActionDocument), 'lock_version' => '2']), 422);
$badActionDocument = json_decode(json_encode($actionDocument));
$badActionDocument->settings->actions[0]->steps[0]->operation->value = 'red;}body{display:none';
documentRequest($actionParams, array_replace($actionPost, ['document' => json_encode($badActionDocument), 'lock_version' => '2']), 422);
check((int) $pdo->query('SELECT COUNT(*) FROM quill_block')->fetchColumn() === $actionBlockCount, 'Invalid actions and missing references reject before database writes');
$eventDocument = json_decode(json_encode($actionDocument));
foreach (\Quill\services\actions\ActionDefinitions::BINDINGS as $trigger => $field) {
    if ($trigger === 'click') continue;
    $definition = (object) ['id' => 'qa-' . $trigger, 'name' => 'Event ' . $trigger, 'trigger' => $trigger, 'steps' => [(object) ['id' => 'qs-' . $trigger, 'target' => (object) ['type' => 'self'], 'condition' => null, 'operation' => (object) ['type' => 'toggle-class', 'value' => 'active']]]];
    if ($trigger === 'keydown') $definition->key = 'Enter';
    $eventDocument->settings->actions[] = $definition;
    $eventDocument->sections[0]->blocks[0]->children[0]->_quill->editor->{$field} = $definition->id;
}
$eventSaved = documentRequest($actionParams, array_replace($actionPost, ['document' => json_encode($eventDocument), 'lock_version' => '2']), 200);
$eventSnapshot = new QuillPageSnapshot(); $eventSnapshot->selectByID($eventSaved['snapshot_id']);
$eventResult = (new QuillToUrpcConverter())->convert(new SnapshotQuillContentSource($eventSnapshot));
$eventAttributes = $eventResult->document->getBlocks('main')[0]->getBlockChildren()[0]->getBlockAttributes();
check(count($eventResult->actions) === 9 && $eventAttributes['data-quill-on-mouseenter'] === 'qa-mouseenter' && $eventAttributes['data-quill-on-keydown'] === 'qa-keydown' && $eventResult->actions[8]->key === 'Enter', 'All action triggers, independent bindings and key filters survive Save/Publish');
$badEventDocument = json_decode(json_encode($eventDocument));
$badEventDocument->sections[0]->blocks[0]->children[0]->_quill->editor->onFocus = 'qa-mouseenter';
documentRequest($actionParams, array_replace($actionPost, ['document' => json_encode($badEventDocument), 'lock_version' => '3']), 422);
$badEventDocument = json_decode(json_encode($eventDocument));
$badEventDocument->settings->actions[8]->key = 'unsupported-key';
documentRequest($actionParams, array_replace($actionPost, ['document' => json_encode($badEventDocument), 'lock_version' => '3']), 422);
$actionPage->selectByID($actionOwner['id']);
echo $saveSuiteOutput;
echo "Passed editor persistence checks; $count total controller/model/template checks.\n";
