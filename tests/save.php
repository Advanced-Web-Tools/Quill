<?php
// Run with: php tests/save.php. AWT_FRAMEWORK_CLASSES can override the sibling framework path.
$framework = getenv('AWT_FRAMEWORK_CLASSES') ?: dirname(__DIR__, 3) . '/Advanced-Web-Tools-Framework/awt_src/classes';
if (!is_dir($framework)) throw new RuntimeException('Set AWT_FRAMEWORK_CLASSES to the AWT classes directory.');
$tmp = sys_get_temp_dir() . '/quill-save-test-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
register_shutdown_function(static function () use ($tmp): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($tmp);
});
define('CONFIG', $tmp);
define('DATA', $tmp . '/');
define('DEBUG', true);
file_put_contents($tmp . '/awt_db.php', "<?php define('DB_TYPE', 'sqlite'); define('DB_NAME', ':memory:');");
spl_autoload_register(static function (string $class) use ($framework): void {
    $relative = str_replace('\\', '/', $class) . '.php';
    $path = match (true) {
        str_starts_with($class, 'Quill\\') => dirname(__DIR__) . '/' . $relative,
        str_starts_with($class, 'Dashboard\\') => dirname(__DIR__, 2) . '/Dashboard/' . $relative,
        str_starts_with($class, 'URPC\\') => (getenv('URPC_PACKAGE_ROOT') ?: dirname(__DIR__, 2) . '/URPC') . '/' . $relative,
        default => $framework . '/' . $relative,
    };
    if (is_file($path)) require_once $path;
});
session_save_path($tmp);
session_start();
$_SESSION['admin'] = ['id' => 1, 'token' => 'authenticated-admin'];
$_SESSION['_token'] = 'test-csrf';
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$shared = ['DBEngine' => ['PDO' => $pdo]];
$pdo->exec('CREATE TABLE awt_table (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, creator INTEGER)');
$pdo->exec('CREATE TABLE awt_table_structure (id INTEGER PRIMARY KEY AUTOINCREMENT, table_id INTEGER, column_name TEXT, column_type TEXT)');
$pdo->exec("CREATE TABLE awt_admin (id INTEGER PRIMARY KEY, token TEXT, username TEXT, profile_picture TEXT, permission_level INTEGER, password TEXT)");
$pdo->exec("INSERT INTO awt_admin VALUES (1, 'authenticated-admin', 'tester', NULL, 0, '')");
$common = 'id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP';
$pdo->exec("CREATE TABLE quill_page ($common, name TEXT NOT NULL, description TEXT, page_json TEXT NOT NULL, status TEXT DEFAULT 'draft', source_type TEXT DEFAULT 'quill', deleted_at TEXT, lock_version INTEGER DEFAULT 1)");
$pdo->exec("CREATE TABLE quill_component ($common, name TEXT NOT NULL, description TEXT, component_json TEXT NOT NULL, deleted_at TEXT, lock_version INTEGER DEFAULT 1)");
$pdo->exec("CREATE TABLE quill_block ($common, block_json TEXT NOT NULL, deleted_at TEXT, lock_version INTEGER DEFAULT 1)");
$pdo->exec("CREATE TABLE quill_dynamic_route ($common, path TEXT NOT NULL UNIQUE, page_id INTEGER REFERENCES quill_page(id))");
$controller = new Quill\controllers\QuillSaveController();
$count = 0;
function check(bool $condition, string $message): void {
    global $count;
    if (!$condition) throw new RuntimeException($message);
    $count++;
}
function request(string $action, array $post, int $expected, string $method = 'POST'): array {
    global $controller;
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = $method;
    $response = $controller->$action([]);
    $status = new ReflectionProperty(response\Response::class, 'statusCode');
    $payload = new ReflectionProperty(response\Response::class, 'payload');
    $data = $payload->getValue($response);
    check($status->getValue($response) === $expected, "$action expected $expected: " . json_encode($data));
    return $data;
}
request('page', ['name' => 'Forged'], 403);
request('page', ['_token' => 'wrong', 'name' => 'Forged'], 403);
request('page', ['_token' => 'test-csrf', 'name' => 'GET'], 405, 'GET');
check((int)$pdo->query('SELECT COUNT(*) FROM quill_page')->fetchColumn() === 0, 'Rejected requests must not write');
$page = request('page', ['_token' => 'test-csrf', 'name' => ' Products ', 'description' => 'Catalog'], 201);
$row = $pdo->query('SELECT * FROM quill_page')->fetch(PDO::FETCH_ASSOC);
check($row['name'] === 'Products' && $row['status'] === 'draft' && $row['page_json'] === '{}' && (int)$row['admin_id'] === 1, 'Page defaults and author');
request('component', ['_token' => 'test-csrf', 'name' => 'Header', 'description' => ''], 201);
check($pdo->query('SELECT component_json FROM quill_component')->fetchColumn() === '{}', 'Component document');
request('page', ['_token' => 'test-csrf', 'name' => ' '], 422);
request('page', ['_token' => 'test-csrf', 'name' => ['invalid']], 422);
request('page', ['_token' => 'test-csrf', 'name' => 'Title', 'admin_id' => 7], 422);
$segments = [['type' => 'part', 'value' => 'products'], ['type' => 'param', 'value' => 'id'], ['type' => 'part', 'value' => 'details']];
$base = ['_token' => 'test-csrf', 'segments' => $segments, 'page_id' => (string)$page['id']];
request('route', $base, 201);
$route = $pdo->query('SELECT * FROM quill_dynamic_route')->fetch(PDO::FETCH_ASSOC);
check($route['path'] === '/products/{id}/details' && (int)$route['page_id'] === $page['id'] && (int)$route['admin_id'] === 1, 'Route assembly, link, and author');
request('route', $base, 422);
request('route', ['_token' => 'test-csrf', 'segments' => array_reverse($segments)], 201);
check($pdo->query('SELECT path FROM quill_dynamic_route ORDER BY id DESC LIMIT 1')->fetchColumn() === '/details/{id}/products', 'Submitted order is preserved');
request('route', ['_token' => 'test-csrf', 'page_id' => ''], 201);
check($pdo->query("SELECT page_id FROM quill_dynamic_route WHERE path = '/'")->fetchColumn() === null, 'Root route may have no page');
foreach ([
    [['type' => 'param', 'value' => 'bad-name']],
    [['type' => 'part', 'value' => 'a/b']],
    [['type' => 'part', 'value' => '..']],
    [['type' => 'param', 'value' => 'id'], ['type' => 'param', 'value' => 'id']],
    [['type' => 'other', 'value' => 'x']],
    [['type' => 'part', 'value' => str_repeat('x', 512)]],
    [1 => ['type' => 'part', 'value' => 'sparse']],
    array_fill(0, 65, ['type' => 'part', 'value' => 'x']),
    'invalid',
] as $invalid) request('route', ['_token' => 'test-csrf', 'segments' => $invalid], 422);
$base['segments'][0]['value'] = 'missing-page';
$base['page_id'] = '999';
request('route', $base, 422);
(new database\DatabaseManager())->table('quill_page')->where(['id' => $page['id']])
    ->update(['deleted_at' => date('Y-m-d H:i:s')]);
// A new path avoids the duplicate check and verifies deleted-page validation.
$base['page_id'] = (string)$page['id'];
request('route', $base, 422);
check((int)$pdo->query('SELECT COUNT(*) FROM quill_dynamic_route')->fetchColumn() === 3, 'Invalid routes must not write');

// Manage metadata without replacing document content or changing its author.
$editable = request('page', ['_token' => 'test-csrf', 'name' => 'Editable page'], 201);
(new database\DatabaseManager())->table('quill_page')->where(['id' => $editable['id']])->update(['page_json' => '{"content":"keep me"}']);
$edit = ['_token' => 'test-csrf', 'id' => (string)$editable['id'], 'lock_version' => '1', 'name' => 'Renamed', 'description' => 'Updated'];
request('updatePage', array_replace($edit, ['_token' => 'forged']), 403);
request('updatePage', array_replace($edit, ['id' => '999']), 404);
request('updatePage', array_replace($edit, ['name' => '']), 422);
request('updatePage', $edit, 200);
$saved = $pdo->query('SELECT * FROM quill_page WHERE id = ' . $editable['id'])->fetch(PDO::FETCH_ASSOC);
check($saved['name'] === 'Renamed' && $saved['page_json'] === '{"content":"keep me"}' && (int)$saved['lock_version'] === 2 && (int)$saved['admin_id'] === 1, 'Metadata update preserves content and author, increments version');
request('updatePage', $edit, 409);
request('updateComponent', ['_token' => 'test-csrf', 'id' => '1', 'lock_version' => '1', 'name' => 'Updated header'], 200);
check($pdo->query('SELECT component_json FROM quill_component WHERE id = 1')->fetchColumn() === '{}', 'Component update preserves content');
$editRoute = ['_token' => 'test-csrf', 'id' => (string)$route['id'], 'segments' => [['type' => 'part', 'value' => 'catalog'], ['type' => 'param', 'value' => 'slug']], 'page_id' => (string)$editable['id']];
request('updateRoute', $editRoute, 200);
request('updateRoute', $editRoute, 200);
check($pdo->query('SELECT path FROM quill_dynamic_route WHERE id = ' . $route['id'])->fetchColumn() === '/catalog/{slug}', 'Route management saves assembled parts');
request('updateRoute', array_replace($editRoute, ['segments' => []]), 422);
$deletePage = ['_token' => 'test-csrf', 'id' => (string)$editable['id'], 'lock_version' => '2'];
request('deletePage', $deletePage, 409);
request('deleteRoute', ['_token' => 'forged', 'id' => (string)$route['id']], 403);
request('deleteRoute', ['_token' => 'test-csrf', 'id' => (string)$route['id']], 200);
request('deleteRoute', ['_token' => 'test-csrf', 'id' => (string)$route['id']], 404);
request('deletePage', $deletePage, 200);
$deleted = $pdo->query('SELECT * FROM quill_page WHERE id = ' . $editable['id'])->fetch(PDO::FETCH_ASSOC);
check($deleted['deleted_at'] !== null && $deleted['page_json'] === '{"content":"keep me"}' && (int)$deleted['lock_version'] === 3, 'Page deletion retains content and marks it deleted');
request('updatePage', array_replace($edit, ['lock_version' => '3']), 404);
request('deleteComponent', ['_token' => 'test-csrf', 'id' => '1', 'lock_version' => '2'], 200);
check($pdo->query('SELECT deleted_at FROM quill_component WHERE id = 1')->fetchColumn() !== null, 'Component deletion is soft');
request('deleteComponent', ['_token' => 'test-csrf', 'id' => '1', 'lock_version' => '3'], 405, 'GET');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['_token' => 'forged', 'id' => '1'];
ob_start();
$controller->deleteRoute([])->send();
$wireResponse = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
check($wireResponse['status'] === 403 && str_contains($wireResponse['message'], 'token expired'), 'Serialized JSON exposes actionable errors');
$viewController = new Quill\controllers\QuillController();
$viewController->eventDispatcher = new event\EventDispatcher();
$statusProperty = new ReflectionProperty(response\Response::class, 'statusCode');
check($statusProperty->getValue($viewController->editor(['type' => 'unknown', 'id' => '1'])) === 404, 'Unknown editor type is rejected');
check($statusProperty->getValue($viewController->editor(['type' => 'page', 'id' => '-1'])) === 404, 'Invalid editor ID is rejected');
check($statusProperty->getValue($viewController->editor(['type' => 'page', 'id' => (string)$editable['id']])) === 404, 'Deleted documents cannot open in editor');
$viewController->setRootPath(dirname(__DIR__) . '/Quill');
$viewController->setViewPath('/views/');
$viewController->viewName = 'editor';
$previousLibxmlErrors = libxml_use_internal_errors(true);
$emptyEditor = request('page', ['_token' => 'test-csrf', 'name' => 'Empty editor'], 201);
$editorView = $viewController->editor(['type' => 'page', 'id' => (string)$emptyEditor['id']]);
check(!isset($editorView->bundle['editorDocumentJson'], $editorView->bundle['editorError']), 'New {} document opens as an empty editor');
$block = new Quill\models\QuillBlock();
$block->block_json = '{"schema_version":1,"kind":"element","descriptor":{"name":"h1","props":{},"renderer":""},"attributes":{},"content":"Loaded from model","children":[]}';
$block->admin_id = 1;
$blockId = $block->saveModel();
(new database\DatabaseManager())->table('quill_page')->where(['id' => $emptyEditor['id']])->update([
    'page_json' => json_encode(['schema_version' => 1, 'sections' => [['name' => 'main', 'blocks' => [$blockId]]]], JSON_THROW_ON_ERROR),
]);
$editorView = $viewController->editor(['type' => 'page', 'id' => (string)$emptyEditor['id']]);
$loadedEditor = json_decode($editorView->bundle['editorDocumentJson'], false, 512, JSON_THROW_ON_ERROR);
check($loadedEditor->sections[0]->blocks[0]->content === 'Loaded from model', 'Editor bootstraps converted working document with expanded blocks');
check($pdo->query('SELECT block_json FROM quill_block WHERE id = ' . $blockId)->fetchColumn() === $block->block_json, 'Opening editor does not write block content');
(new database\DatabaseManager())->table('quill_page')->where(['id' => $emptyEditor['id']])->update(['page_json' => '{"schema_version":1,"sections":[{"name":"main","blocks":[999]}]}']);
$editorView = $viewController->editor(['type' => 'page', 'id' => (string)$emptyEditor['id']]);
check(str_contains($editorView->bundle['editorError'], 'block[999]') && !isset($editorView->bundle['editorDocumentJson']), 'Conversion failures are passed to editor rather than rendered as empty content');
libxml_clear_errors();
libxml_use_internal_errors($previousLibxmlErrors);

// Render the real nested Quill templates with a minimal Dashboard shell.
define('PACKAGES', $tmp . '/templates/');
define('HOSTNAME', 'http://localhost');
mkdir(PACKAGES . 'Quill/views/templates', 0700, true);
mkdir(PACKAGES . 'Dashboard/views/templates', 0700, true);
mkdir($tmp . '/compiled');
file_put_contents(PACKAGES . 'Dashboard/views/templates/main.awt.php', '@yield("content")');
foreach (['index', 'components', 'routes', 'editor', 'templates/quill_dashboard'] as $file) {
    copy(dirname(__DIR__) . '/Quill/views/' . $file . '.awt.php', PACKAGES . 'Quill/views/' . $file . '.awt.php');
}
$editor = new render\TemplateEngine\BladeOne(PACKAGES, $tmp . '/compiled', 5);
$editor->setFileExtension('.awt.php');
$editor->addAssetDict(0, '/awt_packages/Quill/views/assets');
$editorHtml = $editor->run('Quill.views.editor', ['tab' => 'Pages', 'editorName' => 'Page <test>']);
check(str_contains($editorHtml, 'Page &lt;test&gt;') && str_contains($editorHtml, '<!doctype html>') && !str_contains($editorHtml, 'navbar') && !str_contains($editorHtml, 'quill-create-dialog'), 'Standalone editor renders without Dashboard template');
foreach (['editor-tree', 'editor-canvas', 'editor-properties', 'quill-editor-data'] as $elementId) {
    check(str_contains($editorHtml, 'id="' . $elementId . '"'), 'Editor contains ' . $elementId);
}
$safeEditor = $editor->run('Quill.views.editor', ['editorName' => 'Unsafe text', 'editorDocumentJson' => json_encode([
    'schema_version' => 1, 'sections' => [['name' => 'main', 'blocks' => [['descriptor' => ['name' => 'p', 'props' => new stdClass(), 'renderer' => ''], 'attributes' => new stdClass(), 'content' => '</script><script>alert(1)</script>', 'children' => []]]]],
], JSON_THROW_ON_ERROR)]);
check(!str_contains($safeEditor, '</script><script>alert') && str_contains($safeEditor, '\\u003C'), 'Bootstrap JSON cannot escape its script container');
$fixture = new class {
    public string $name = 'Name <test>';
    public string $description = 'Description <test>';
    public string $path = '/products/{id}';
    public string $status = 'draft';
    public string $created_at = '2026-10-15 12:00:00';
    public string $updated_at = '2026-10-15 12:30:00';
    public int $lock_version = 7;
    public ?int $page_id = 42;
    public function getKey(): int { return 42; }
};
foreach (['index' => ['Pages', 'page'], 'components' => ['Components', 'component'], 'routes' => ['Routes', 'route']] as $view => [$tab, $kind]) {
    foreach ([false, true] as $populated) {
        $blade = new render\TemplateEngine\BladeOne(PACKAGES, $tmp . '/compiled', 5);
        $blade->setFileExtension('.awt.php');
        $blade->addAssetDict(0, '/awt_packages/Quill/views/assets');
        $html = $blade->run('Quill.views.' . $view, [
            'tab' => $tab, 'pages' => $populated ? [$fixture] : [],
            'components' => $populated ? [$fixture] : [], 'routes' => $populated ? [$fixture] : [],
            'pageNames' => [42 => $fixture->name],
        ]);
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        check($xpath->query('//dialog[@id="quill-create-dialog"]')->length === 1, "$view dialog");
        check($xpath->query('//button[@data-quill-create]')->length === ($populated ? 1 : 2), "$view triggers");
        check($xpath->query('//form[@method="post" and @action="/dashboard/quill/create/' . $kind . '"]')->length === 1,
            "$view form endpoint: " . $xpath->evaluate('string(//form/@action)'));
        check($xpath->query('//form//input[@name="_token" and @value="test-csrf"]')->length === ($view === 'index' ? 3 : 2), "$view mutation forms include CSRF fields");
        check($xpath->query('//dialog[@id="quill-delete-dialog"]')->length === 1, "$view separate delete confirmation");
        if ($populated) {
            check($xpath->query('//button[@data-quill-manage and contains(@class,"btn-ghost")]')->length === 1, "$view ghost manage button");
            $item = json_decode($xpath->evaluate('string(//button/@data-quill-manage)'), true, flags: JSON_THROW_ON_ERROR);
            check($item['id'] === 42, "$view manage metadata");
            if ($kind !== 'route') check($xpath->query('//a[@href="/quill/editor/' . $kind . '/42" and @target="_blank" and @rel="noopener"]')->length === 1, "$view editor opens in a new tab");
        }
        check(str_contains($html, '/awt_packages/Quill/views/assets/js/quill-create.js'), "$view script asset");
        if ($view === 'routes') {
            check($xpath->query('//button[@data-route-add]')->length === 2, 'Both route add controls');
            if ($populated) check(str_contains($html, 'value="42">Name &lt;test&gt;'), 'Escaped page option');
        }
    }
}

// The actual save controller can be constructed by the framework factory.
$factory = (new object\ObjectFactory())->setType(controller\Controller::class)
    ->setClassPath(dirname(__DIR__) . '/Quill/controllers/QuillSaveController.php');
check($factory->create() instanceof Quill\controllers\QuillSaveController, 'Save controller factory');
$route = (new router\Router('/dashboard/quill/create/route', 'route', $factory))->setMethod('POST');
check($route->requiresCsrf(), 'Save endpoint retains router CSRF protection');
$matcher = new router\matching\SegmentPathMatcher();
check($matcher->match('/products/{id}/details', '/products/42/details') === ['id' => '42'], 'Saved path matches framework parameters');
check($matcher->match('/quill/editor/{type}/{id}', '/quill/editor/page/42') === ['type' => 'page', 'id' => '42'], 'Editor URLs carry type and ID');
echo "Passed $count save, validation, template, and registration checks.\n";
