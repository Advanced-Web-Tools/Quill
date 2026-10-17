<?php
// Run: php tests/conversion.php. Uses the real sibling AWT and URPC packages.

use Quill\models\QuillPage;
use Quill\models\QuillPageSnapshot;
use Quill\services\conversion\shared\ConversionException;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource;
use Quill\services\conversion\quillToUrpc\UrpcJsonSerializer;
use Quill\services\conversion\quillToUrpc\WorkingQuillContentSource;

$framework = getenv('AWT_FRAMEWORK_CLASSES') ?: dirname(__DIR__, 3) . '/Advanced-Web-Tools-Framework/awt_src/classes';
$urpc = getenv('URPC_PACKAGE_ROOT') ?: dirname(__DIR__, 2) . '/URPC';
if (!is_dir($framework) || !is_dir($urpc . '/URPC/api')) throw new RuntimeException('Provide the AWT and URPC source directories.');
$temporary = sys_get_temp_dir() . '/quill-conversion-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
define('CONFIG', $temporary);
define('DATA', $temporary . '/');
define('DEBUG', true);
file_put_contents($temporary . '/awt_db.php', "<?php define('DB_TYPE', 'sqlite'); define('DB_NAME', ':memory:');");
register_shutdown_function(static function () use ($temporary): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($temporary);
});
spl_autoload_register(static function (string $class) use ($framework, $urpc): void {
    $relative = str_replace('\\', '/', $class) . '.php';
    $path = match (true) {
        str_starts_with($class, 'Quill\\') => dirname(__DIR__) . '/' . $relative,
        str_starts_with($class, 'URPC\\') => $urpc . '/' . $relative,
        default => $framework . '/' . $relative,
    };
    if (is_file($path)) require_once $path;
});
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$shared = ['DBEngine' => ['PDO' => $pdo]];
foreach ([
    'quill_page' => 'page_json TEXT, source_type TEXT, deleted_at TEXT, published_snapshot_id INTEGER',
    'quill_component' => 'component_json TEXT, deleted_at TEXT, published_snapshot_id INTEGER',
    'quill_block' => 'block_json TEXT, deleted_at TEXT',
    'quill_page_snapshot' => 'page_id INTEGER, page_json TEXT, source_type TEXT',
    'quill_component_snapshot' => 'component_id INTEGER, component_json TEXT',
    'quill_block_snapshot' => 'block_id INTEGER, block_json TEXT',
    'quill_page_snapshot_block' => 'page_snapshot_id INTEGER, block_id INTEGER, block_snapshot_id INTEGER',
    'quill_page_snapshot_component' => 'page_snapshot_id INTEGER, component_id INTEGER, component_snapshot_id INTEGER',
    'quill_component_snapshot_block' => 'component_snapshot_id INTEGER, block_id INTEGER, block_snapshot_id INTEGER',
    'quill_component_snapshot_component' => 'owner_snapshot_id INTEGER, component_id INTEGER, referenced_snapshot_id INTEGER',
] as $table => $columns) $pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, $columns)");
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function fails(callable $operation, string ...$fragments): void
{
    try { $operation(); } catch (ConversionException $error) {
        foreach ($fragments as $fragment) check(str_contains($error->getMessage(), $fragment), $error->getMessage() . ' must contain ' . $fragment);
        return;
    }
    throw new RuntimeException('Expected conversion failure: ' . implode(', ', $fragments));
}
function put(string $table, array $values): void
{
    global $pdo;
    $columns = implode(', ', array_keys($values));
    $parameters = implode(', ', array_fill(0, count($values), '?'));
    $pdo->prepare("INSERT OR REPLACE INTO $table ($columns) VALUES ($parameters)")->execute(array_values($values));
    (new database\cache\DatabaseCache())->invalidateTable($table);
}
function remove(string $table, int $id): void
{
    (new database\DatabaseManager())->table($table)->where(['id' => $id])->delete();
}
function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
function document(array $blocks, array $additionalSections = []): string
{
    return json(['schema_version' => 1, 'sections' => array_merge([['name' => 'main', 'blocks' => $blocks]], $additionalSections),
        'assets' => ['styles' => ['page.css'], 'scripts' => []], 'settings' => (object) ['seo' => 'metadata']]);
}
function element(string $tag = 'h1', string $content = 'Naslov', array $children = []): stdClass
{
    return (object) ['schema_version' => 1, 'kind' => 'element', 'definition_key' => 'Quill:heading',
        'descriptor' => (object) ['name' => $tag, 'props' => new stdClass(), 'renderer' => ''],
        'attributes' => new stdClass(), 'content' => $content, 'children' => $children,
        'styles' => (object) ['base' => new stdClass(), 'tablet' => new stdClass(), 'mobile' => new stdClass()],
        'bindings' => new stdClass(), 'custom_settings' => new stdClass(), 'editor' => (object) ['locked' => true]];
}
function block(int $id, stdClass $value): void { put('quill_block', ['id' => $id, 'block_json' => json($value)]); }
function working(string $json): WorkingQuillContentSource
{
    put('quill_page', ['id' => 1, 'page_json' => $json, 'source_type' => 'quill']);
    $page = new QuillPage();
    $page->selectByID(1);
    return new WorkingQuillContentSource($page);
}
function snapshot(): SnapshotQuillContentSource
{
    $page = new QuillPageSnapshot();
    $page->selectByID(10);
    return new SnapshotQuillContentSource($page);
}

$converter = new QuillToUrpcConverter(['tablet' => 900, 'mobile' => 600]);
$serializer = new UrpcJsonSerializer();
block(1, element('h1', '<Naslov> & tekst'));
$source = working(document([1], [['name' => 'footer', 'blocks' => []]]));
$result = $converter->convert($source);
$root = $result->document->getBlocks('main')[0];
check($result->document instanceof URPC\api\contracts\IURPCDocument, 'Returns real URPC document');
check($root->getBlockContent() === '<Naslov> & tekst', 'Content stays unescaped, locked block stays visible');
check($root->getBlockDescriptor()->getBlockUid() === '', 'No invented UID');
check($root->getBlockDescriptor()->getSourceKey() === 'Quill/working/page/1', 'Stable source namespace');
check(array_keys($result->document->getSections()) === ['main', 'footer'], 'Ordered sections including empty sections');
$transport = $serializer->serializeDocument($result->document);
check(str_contains($transport, '"props":{}') && str_contains($transport, '"attributes":{}'), 'Empty maps serialize as objects');
check(!str_contains($transport, 'editor') && !str_contains($transport, 'definition_key') && !str_contains($transport, 'seo'), 'Editor and page metadata removed');
$sectionJson = $serializer->serializeSection($result->document, 'main');
check(isset(json_decode($sectionJson)->blocks) && !isset(json_decode($sectionJson)->sections), 'Section export is loader envelope');
fails(fn () => $serializer->serializeSection($result->document, 'absent'), 'sections/absent');
$provider = new class($sectionJson) implements URPC\service\contracts\loader\IURPCLoaderProvider {
    public function __construct(private string $json) {}
    public function getSourceKey(): string { return 'Quill/test'; }
    public function getContent(): string { return $this->json; }
};
check((new URPC\service\loader\URPCLoader())->load($provider)[0]->getBlockContent() === '<Naslov> & tekst', 'Actual URPC loader accepts section export');

block(2, element('p', 'second'));
block(3, element('section', '', [2, 1]));
$result = $converter->convert(working(document([3, 2])));
$roots = $result->document->getBlocks('main');
check(count($roots) === 2 && $roots[1]->getBlockContent() === 'second', 'Root order');
check(array_map(fn ($child) => $child->getBlockContent(), $roots[0]->getBlockChildren()) === ['second', '<Naslov> & tekst'], 'Nested ID order');
$typed = element();
$typed->descriptor->props = json_decode('{"object":{},"array":[],"nested":{"list":[{},[]],"numeric":{"0":"zero"}}}');
block(4, $typed);
$result = $converter->convert(working(document([4])));
$props = json_decode($serializer->serializeSection($result->document, 'main'))->blocks[0]->descriptor->props;
check($props == $typed->descriptor->props && $props->nested->list[0] instanceof stdClass && is_array($props->nested->list[1]), 'Nested object/list distinction');

$styled = element('p', 'component');
$styled->attributes->class = 'original';
$styled->styles->base->color = 'red';
$styled->styles->tablet->color = 'blue';
$styled->styles->mobile->color = 'green';
block(5, $styled);
put('quill_component', ['id' => 7, 'component_json' => document([5], [['name' => 'end', 'blocks' => [2]]])]);
$reference = (object) ['schema_version' => 1, 'kind' => 'component', 'component_id' => 7];
block(6, $reference);
$source = working(document([1, 6, 6, 1]));
$before = $pdo->query('SELECT block_json FROM quill_block WHERE id=5')->fetchColumn();
$result = $converter->convert($source);
$roots = $result->document->getBlocks('main');
check(array_map(fn ($root) => $root->getBlockContent(), $roots) === ['<Naslov> & tekst', 'component', 'second', 'component', 'second', '<Naslov> & tekst'], 'Components flatten all sections in place');
check($roots[1] !== $roots[3] && $roots[1]->getBlockAttributes()['class'] !== $roots[3]->getBlockAttributes()['class'], 'Repeated instances have independent trees and CSS scopes');
check(str_contains($result->scopedCss, '@media (max-width: 900px)') && str_contains($result->scopedCss, '@media (max-width: 600px)'), 'Configured responsive breakpoints');
check(str_contains($roots[1]->getBlockAttributes()['class'], 'original quill-scope-'), 'Scoped CSS preserves classes');
check($result->assets === ['styles' => ['page.css'], 'scripts' => []], 'Assets merged in order without duplicates');
check($serializer->serializeDocument($result->document) === $serializer->serializeDocument($converter->convert($source)->document), 'Deterministic calls with isolated contexts');
check($before === $pdo->query('SELECT block_json FROM quill_block WHERE id=5')->fetchColumn(), 'No source document mutation');
fails(fn () => (new QuillToUrpcConverter())->convert($source), 'block[5]', 'tablet', 'breakpoint');
$stateful = element('button', 'States');
$stateful->attributes->hidden = true;
$stateful->styles->base = (object) ['display' => 'flex', 'color' => 'black'];
foreach (['hover', 'focus', 'focus-visible', 'active', 'disabled', 'checked'] as $state) {
    $stateful->styles->{$state} = (object) ['color' => 'purple'];
}
$stateful->styles->{'mobile:hover'} = (object) ['color' => 'orange'];
$stateful->styles->base->{'--quill-center-x'} = '-50%';
$stateful->styles->base->{'--quill-move-x'} = '12px';
$stateful->styles->base->translate = 'calc(var(--quill-center-x) + var(--quill-move-x)) 0px';
$stateful->styles->base->rotate = '45deg';
$stateful->styles->base->scale = '1.5 0.5';
$stateful->styles->base->{'transform-origin'} = '100% 0%';
$stateful->styles->base->transform = 'skewX(10deg)';
block(61, $stateful);
$stateResult = (new QuillToUrpcConverter(['mobile' => 600]))->convert(working(document([61])));
check((bool) preg_match('/@media \(max-width: 600px\)\{\.quill-scope-[a-f0-9]+:hover\{color:orange;\}\}/', $stateResult->scopedCss), 'Combined viewport/state exports a pseudo class inside its media query');
check(str_contains($stateResult->scopedCss, 'translate:calc(var(--quill-center-x) + var(--quill-move-x)) 0px;')
    && str_contains($stateResult->scopedCss, 'rotate:45deg;scale:1.5 0.5;transform-origin:100% 0%;transform:skewX(10deg);'), 'Centering expressions and independent transforms survive public CSS export');
foreach (['hover', 'focus', 'focus-visible', 'active', 'disabled', 'checked'] as $state) {
    check(str_contains($stateResult->scopedCss, ':' . $state . '{color:purple;}'), 'CSS state exports without a viewport breakpoint: ' . $state);
}
check($stateResult->document->getBlocks('main')[0]->getBlockAttributes()['hidden'] === true
    && str_contains($stateResult->scopedCss, '[hidden]{display:none!important;}'), 'Start hidden survives export and overrides authored display until Show removes hidden');
$icon = element('i');
$icon->definition_key = 'Quill:icon';
$icon->attributes = (object) ['class' => 'fa-brands fa-github', 'data-quill-icon' => true, 'aria-label' => 'GitHub', 'role' => 'img'];
$icon->styles->base = (object) ['background-image' => 'linear-gradient(90deg, red 0%, blue 100%)', 'background-clip' => 'text', '-webkit-text-fill-color' => 'transparent', 'box-shadow' => '0px 4px 12px rgba(0, 0, 0, 0.25)', 'text-shadow' => '0px 2px 4px black', 'border-image-source' => 'radial-gradient(circle, red, blue)', 'border-image-slice' => '1'];
block(62, $icon);
$iconResult = $converter->convert(working(document([62, 62])));
check($iconResult->assets['styles'] === ['page.css', \Quill\services\icons\FontAwesome::STYLESHEET], 'Icon blocks automatically include the pinned Font Awesome CDN once in public assets');
check(str_contains($iconResult->scopedCss, 'background-image:linear-gradient(') && str_contains($iconResult->scopedCss, 'border-image-source:radial-gradient(') && str_contains($iconResult->scopedCss, 'box-shadow:0px 4px 12px rgba(') && str_contains($iconResult->scopedCss, 'text-shadow:0px 2px 4px black;'), 'Gradients, borders and both shadow types survive public CSS export');
check($iconResult->document->getBlocks('main')[0]->getBlockAttributes()['aria-label'] === 'GitHub', 'Icon accessibility attributes survive conversion');
$fontBlock = element('p', 'Google Fonts');
$fontBlock->styles->base = (object) ['font-family' => '"Inter", sans-serif', 'font-weight' => '400', 'font-style' => 'normal'];
$fontBlock->styles->mobile = (object) ['font-weight' => '700'];
$fontBlock->styles->{'mobile:hover'} = (object) ['font-style' => 'italic'];
block(63, $fontBlock);
$fontResult = $converter->convert(working(document([63, 63])));
$fontUrls = array_values(array_filter($fontResult->assets['styles'], fn ($url) => str_starts_with($url, 'https://fonts.googleapis.com/')));
check($fontUrls === ['https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700;1,700&display=swap'], 'Public fonts combine used viewport/state variants into one canonical stylesheet');
$inheritedFont = element('strong', 'Inherited bold');
block(64, $inheritedFont);
$fontBlock->children = [64];
$fontBlock->styles = (object) ['base' => (object) ['font-family' => '"Inter", sans-serif', 'font-weight' => '400']];
block(63, $fontBlock);
$fontResult = $converter->convert(working(document([63])));
check(in_array('https://fonts.googleapis.com/css2?family=Inter:ital,wght@0,400;0,700&display=swap', $fontResult->assets['styles'], true), 'Descendant headings and strong text request inherited font weights');
$componentFont = element('p', 'Component font');
$componentFont->attributes->style = 'font-family:"Open Sans",sans-serif;font-weight:600;font-style:italic';
block(66, $componentFont);
put('quill_component', ['id' => 77, 'component_json' => document([66])]);
block(67, (object) ['schema_version' => 1, 'kind' => 'component', 'component_id' => 77]);
$componentFontResult = $converter->convert(working(document([67, 67])));
check(count(array_filter($componentFontResult->assets['styles'], fn ($url) => $url === 'https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@1,600&display=swap')) === 1, 'Repeated components retain inline font styles and share one font dependency');
$unknownFont = element('p', 'System font');
$unknownFont->attributes->style = 'font-family:Arial,sans-serif';
block(68, $unknownFont);
check($converter->convert(working(document([68])))->assets['styles'] === ['page.css'], 'System and unknown fonts do not generate remote requests');
check(\Quill\services\fonts\GoogleFonts::variant(\Quill\services\fonts\GoogleFonts::find('Bungee Spice'), 700, 1) === '0,400', 'Static fonts without italic fall back to an available variant');
check(\Quill\services\fonts\GoogleFonts::variant(\Quill\services\fonts\GoogleFonts::find('Inter'), 450, 1) === '1,450', 'Variable fonts retain supported intermediate weights');
block(8, element('div', '', [8]));
fails(fn () => $converter->convert(working(document([8]))), 'block[8]', 'children/0', 'Cycle');
put('quill_component', ['id' => 7, 'component_json' => document([6])]);
fails(fn () => $converter->convert(working(document([6]))), 'component[7]', 'Cycle');
fails(fn () => (new QuillToUrpcConverter(maxDepth: 2))->convert(working(document([3]))), 'block[2]', 'depth');
fails(fn () => $converter->convert(working(document([999]))), 'blocks/0', 'block[999]', 'Missing');
block(9, (object) ['schema_version' => 1, 'kind' => 'component', 'component_id' => 999]);
fails(fn () => $converter->convert(working(document([9]))), 'component[999]', 'Missing');

// Exact manifests, including a nested component that has a different revision scope.
put('quill_page_snapshot', ['id' => 10, 'page_id' => 1, 'page_json' => document([6]), 'source_type' => 'quill']);
put('quill_block_snapshot', ['id' => 106, 'block_id' => 6, 'block_json' => json($reference)]);
put('quill_page_snapshot_block', ['id' => 1, 'page_snapshot_id' => 10, 'block_id' => 6, 'block_snapshot_id' => 106]);
put('quill_component_snapshot', ['id' => 70, 'component_id' => 7, 'component_json' => document([9])]);
put('quill_page_snapshot_component', ['id' => 1, 'page_snapshot_id' => 10, 'component_id' => 7, 'component_snapshot_id' => 70]);
$nestedReference = (object) ['schema_version' => 1, 'kind' => 'component', 'component_id' => 8];
put('quill_block_snapshot', ['id' => 109, 'block_id' => 9, 'block_json' => json($nestedReference)]);
put('quill_component_snapshot_block', ['id' => 1, 'component_snapshot_id' => 70, 'block_id' => 9, 'block_snapshot_id' => 109]);
put('quill_component_snapshot', ['id' => 80, 'component_id' => 8, 'component_json' => document([1])]);
put('quill_component_snapshot_component', ['id' => 1, 'owner_snapshot_id' => 70, 'component_id' => 8, 'referenced_snapshot_id' => 80]);
put('quill_block_snapshot', ['id' => 101, 'block_id' => 1, 'block_json' => json(element('h1', 'snapshot only'))]);
put('quill_component_snapshot_block', ['id' => 2, 'component_snapshot_id' => 80, 'block_id' => 1, 'block_snapshot_id' => 101]);
$result = $converter->convert(snapshot());
check($result->document->getBlocks('main')[0]->getBlockContent() === 'snapshot only', 'Nested component uses its own exact manifest, ignoring working cycle and changed blocks');
remove('quill_block_snapshot', 101);
fails(fn () => $converter->convert(snapshot()), 'component[8]', 'block[1]', 'Missing exact snapshot');
remove('quill_component_snapshot_block', 2);
fails(fn () => $converter->convert(snapshot()), 'block[1]', 'manifest');
remove('quill_component_snapshot_component', 1);
fails(fn () => $converter->convert(snapshot()), 'component[8]', 'manifest');
remove('quill_component_snapshot', 70);
fails(fn () => $converter->convert(snapshot()), 'component[7]', 'Missing exact snapshot');

block(10, (object) ['schema_version' => 1, 'kind' => 'override']);
fails(fn () => $converter->convert(working(document([10]))), 'block[10]', 'Unsupported operation', 'override');
fails(fn () => $converter->convert(working('{')), 'document[working/page/1]', 'Invalid JSON');
fails(fn () => $converter->convert(working('{}')), 'schema_version');
fails(fn () => $converter->convert(working('{"schema_version":2,"sections":[]}')), 'schema_version');
put('quill_block', ['id' => 11, 'block_json' => '{']);
fails(fn () => $converter->convert(working(document([11]))), 'block[11]', 'Invalid JSON');
foreach ([
    ['kind', 'unknown', 'kind'], ['content', null, 'content'], ['content', 123, 'content'],
    ['attributes', [], 'attributes'], ['attributes', (object) ['invalid name' => 'x'], 'attributes'],
    ['attributes', (object) ['class' => []], 'attributes'], ['children', new stdClass(), 'children'],
    ['children', ['1'], 'children/0'], ['bindings', (object) ['text' => 'sql'], 'bindings resolver'],
    ['custom_settings', (object) ['fqcn' => 'Untrusted'], 'QuillSetting'], ['styles', null, 'styles'],
] as [$field, $value, $message]) {
    $invalid = element();
    $invalid->{$field} = $value;
    block(12, $invalid);
    fails(fn () => $converter->convert(working(document([12]))), 'block[12]', $message);
}
foreach (['img', 'script', 'style'] as $tag) {
    block(12, element($tag, 'forbidden'));
    fails(fn () => $converter->convert(working(document([12]))), 'block[12]', $tag);
    block(12, element($tag, '', [1]));
    fails(fn () => $converter->convert(working(document([12]))), 'block[12]', $tag);
}
foreach ([(object) ['name' => 'div bad'], (object) ['name' => 'p', 'props' => []], (object) ['name' => 'p', 'renderer' => 3]] as $descriptor) {
    $invalid = element();
    $invalid->descriptor = $descriptor;
    block(12, $invalid);
    fails(fn () => $converter->convert(working(document([12]))), 'block[12]', 'descriptor');
}
$invalid = element();
$invalid->styles->base = (object) ['color' => 'red;}body{color:blue'];
block(12, $invalid);
fails(fn () => $converter->convert(working(document([12]))), 'block[12]', 'CSS');
block(12, element('br', ''));
check(count($converter->convert(working(document([12])))->document->getBlocks('main')) === 1, 'Empty void element accepted');
check($converter->convert(working(document([])))->scopedCss === '', 'No CSS state leaks between calls');

// Repeated references reuse decoded inputs only within the current conversion.
$memorySource = new class implements Quill\services\conversion\quillToUrpc\QuillContentSource {
    public int $blockReads = 0;
    public function getScopeKey(): string { return 'test/cache'; }
    public function getDocument(string $path): string { return document([1, 1]); }
    public function getBlock(int $blockId, string $path): string
    {
        $this->blockReads++;
        return json(element());
    }
    public function getComponent(int $componentId, string $path): Quill\services\conversion\quillToUrpc\QuillContentSource
    {
        throw new LogicException('This fixture contains no components.');
    }
};
$cachedResult = $converter->convert($memorySource);
check($memorySource->blockReads === 1, 'A repeated block is loaded once per call');
$cachedRoots = $cachedResult->document->getBlocks('main');
$cachedRoots[0]->setBlockContent('changed by caller');
check($cachedRoots[1]->getBlockContent() === 'Naslov', 'Repeated references return independent block objects');
$freshResult = $converter->convert($memorySource);
check($memorySource->blockReads === 2 && $freshResult->document->getBlocks('main')[0]->getBlockContent() === 'Naslov', 'A subsequent call reloads content and has no previous mutable state');
echo "Passed $checks conversion checks using real Quill models, SQLite, and URPC API/loader.\n";
