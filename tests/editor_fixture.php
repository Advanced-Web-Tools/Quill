<?php
// Renders the real template for the headless browser test; does not use a database.
$framework = getenv('AWT_FRAMEWORK_CLASSES') ?: dirname(__DIR__, 3) . '/Advanced-Web-Tools-Framework/awt_src/classes';
spl_autoload_register(static function (string $class) use ($framework): void {
    $relative = str_replace('\\', '/', $class) . '.php';
    $path = match (true) {
        str_starts_with($class, 'Quill\\') => dirname(__DIR__) . '/' . $relative,
        str_starts_with($class, 'URPC\\') => (getenv('URPC_PACKAGE_ROOT') ?: dirname(__DIR__, 2) . '/URPC') . '/' . $relative,
        default => $framework . '/' . $relative,
    };
    if (is_file($path)) require_once $path;
});
$compiled = sys_get_temp_dir() . '/quill-editor-template-' . bin2hex(random_bytes(6));
mkdir($compiled, 0700);
register_shutdown_function(static function () use ($compiled): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    foreach (new DirectoryIterator($compiled) as $file) if ($file->isFile()) unlink($file->getPathname());
    rmdir($compiled);
});
session_save_path($compiled);
session_start();
$_SESSION['_token'] = 'editor-test-token';
define('HOSTNAME', getenv('EDITOR_TEST_ORIGIN') ?: 'http://localhost');
define('PACKAGES', dirname(__DIR__) . '/');
$blade = new render\TemplateEngine\BladeOne(dirname(__DIR__), $compiled, 5);
$blade->setFileExtension('.awt.php');
$blade->addAssetDict(0, '/Quill/views/assets');
function block(string $name, string $content, array $children = [], array $attributes = []): array
{
    return ['descriptor' => ['name' => $name, 'props' => (object) ['empty' => new stdClass(), 'list' => []], 'renderer' => ''],
        'attributes' => (object) $attributes, 'content' => $content, 'children' => $children];
}
$document = ['schema_version' => 1, 'sections' => [
    ['name' => 'main', 'blocks' => [block('section', '', [
        block('h1', 'Build something thoughtful'),
        block('p', 'Select a block on the canvas or in the layer tree.'),
        block('a', 'A preview link', [], ['href' => 'javascript:window.top.location="https://example.com"', 'onclick' => 'window.top.previewScriptExecuted=true']),
    ], ['class' => 'hero'])]],
    ['name' => 'footer', 'blocks' => [block('p', 'Made with Quill')]],
]];
$mode = $argv[1] ?? 'loaded';
if ($mode === 'empty') $document = ['schema_version' => 1, 'sections' => [['name' => 'main', 'blocks' => []]]];
$params = ['editorPropertyAssetBase' => '/Quill/views/assets/js/editor/properties/', 'editorName' => 'My first page', 'editorType' => 'page', 'editorId' => 1, 'editorVersion' => 1,
    'editorDocumentJson' => json_encode($document, JSON_THROW_ON_ERROR),
    'editorPageRoutes' => [['id'=>71,'path'=>'/available/{slug}','page_id'=>null,'page_name'=>null],['id'=>72,'path'=>'/another','page_id'=>2,'page_name'=>'Another page']],
    'editorRoutes' => [['path' => '/', 'name' => 'Home', 'parameters' => []], ['path' => '/contact', 'name' => 'Contact', 'parameters' => []], ['path' => '/products/{slug}', 'name' => 'Product', 'parameters' => ['slug']]],
    'editorCss' => '.hero{padding:28px;background:#f7f6ff;border-radius:12px}.hero h1{font-size:38px;letter-spacing:-1px}',
];
if ($mode === 'component-editor') { $params['editorType'] = 'component'; $params['editorId'] = 14; }
if ($mode === 'connect-data') {
    $params['editorDataSources'] = [['name' => 'products', 'columns' => ['id','slug','title','image','price']], ['name' => 'news', 'columns' => ['id','headline','published_at']]];
    $params['editorDataRoutes'] = [['path' => '/products/{slug}', 'parameters' => ['slug']]];
}
if ($mode === 'components') $params['editorComponents'] = [['id' => 12, 'name' => 'Saved card', 'description' => 'A reusable card'], ['id' => 13, 'name' => 'Empty component', 'description' => '']];
if ($mode === 'error') $params['editorError'] = 'document/sections/0/blocks/0/block[999]: Missing working block 999.';
$extensionDispatcher = new event\EventDispatcher();
if ($mode === 'extensions') {
    $extensionDispatcher->addListener(Quill\api\events\EditorBlocksRequestEvent::NAME, new class implements event\interfaces\IEventListener {
        public function handle(event\interfaces\IEvent $event): array
        {
            $event->bundle()['blockRegistry']->add(new Quill\api\editor\BlockDefinition('Demo:card', 'External card', '★', 'Registered by another package',
                '{"blocks":[{"descriptor":{"name":"article","props":{"title":"Initial caption"}},"attributes":{"data-enabled":"on","style":"padding:20px"},"children":[{"descriptor":{"name":"h2"},"content":"Nested heading"},{"descriptor":{"name":"p"},"content":"Nested body"}]}]}'
            ));
            return [];
        }
    });
    $extensionDispatcher->addListener(Quill\api\events\EditorPropertiesRequestEvent::NAME, new class implements event\interfaces\IEventListener {
        public function handle(event\interfaces\IEvent $event): array
        {
            $event->bundle()['propertyRegistry']->add(new Quill\api\editor\PropertyDefinition('Demo:appearance', 'appearance', [
                '/tests/fixtures/editor/caption.js', new Quill\api\editor\PropertyElement('/tests/fixtures/editor/detail.js', '/tests/fixtures/editor/detail-condition.js'),
            ], '/tests/fixtures/editor/card-condition.js', 'Appearance'));
            return [];
        }
    });
}
$params['editorExtensions'] = (new Quill\services\editor\EditorExtensionLoader('/Quill/views/assets'))->load($extensionDispatcher);
echo $blade->run('Quill.views.editor', $params);
