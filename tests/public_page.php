<?php
// Run: php tests/public_page.php. Exercises the real Quill → URPC rendering pipeline.
require __DIR__ . '/editor_save.php';

use context\Context;
use Quill\controllers\PublicPageController;
use Quill\models\QuillBlock;
use Quill\models\QuillDynamicRoute;
use Quill\models\QuillPage;
use Quill\services\conversion\quillToUrpc\QuillToUrpcConverter;
use Quill\services\conversion\shared\ConversionException;
use Quill\services\routes\RouteRegistration;
use URPC\api\contracts\IURPCBlock;
use URPC\api\contracts\IURPCBlockRenderer;
use URPC\api\contracts\IURPCDocument;
use URPC\api\events\BeforeRenderEvent;
use URPC\api\URPCBlock;
use URPC\api\URPCBlockDescriptor;
use URPC\api\URPCDocument;
use URPC\service\contracts\provider\IURPCDocumentProvider;
use URPC\service\renderer\URPC;

$publicChecksStart = $count;
$eventDispatcher = new event\EventDispatcher();
$events = new class implements event\interfaces\IEventListener {
    public int $calls = 0;
    public function handle(event\interfaces\IEvent $event): array
    {
        $this->calls++;
        foreach ($event->getDocument()->getSections() as $blocks) {
            foreach ($blocks as $block) check((bool) preg_match('/^[a-f0-9]{32}$/D', $block->getBlockDescriptor()->getBlockUid()), 'URPC assigns identities before before-render event');
        }
        return [];
    }
};
$eventDispatcher->addListener(BeforeRenderEvent::NAME, $events);

function publicController(QuillPage $page, ?QuillToUrpcConverter $converter = null): PublicPageController
{
    global $eventDispatcher;
    $controller = new PublicPageController();
    $controller->setContext(new Context(dirname(__DIR__) . '/Quill', 'Quill', '1'));
    $controller->eventDispatcher = $eventDispatcher;
    $controller->setPage($page, $converter);
    return $controller;
}
function renderingFails(callable $operation, string ...$fragments): void
{
    try { $operation(); } catch (ConversionException $error) {
        foreach ($fragments as $fragment) check(str_contains($error->getMessage(), $fragment), $error->getMessage() . ' must include ' . $fragment);
        return;
    }
    throw new RuntimeException('Expected a precise render conversion failure.');
}
function publicPage(stdClass $document): QuillPage
{
    $page = new QuillPage();
    $page->name = 'Public page fixture';
    $page->source_type = 'quill';
    $page->admin_id = 1;
    $page->setDocument($document);
    $page->saveModel();
    return $page;
}
function blockDocument(string $content, string $renderer = ''): stdClass
{
    return (object) ['schema_version' => 1, 'kind' => 'element',
        'descriptor' => (object) ['name' => 'p', 'props' => new stdClass(), 'renderer' => $renderer],
        'attributes' => new stdClass(), 'content' => $content, 'children' => []];
}
function pageDocument(int $blockId): stdClass
{
    return (object) ['schema_version' => 1, 'sections' => [(object) ['name' => 'main', 'blocks' => [$blockId]]]];
}

// editor_save left a published snapshot and a newer, different working draft.
$page->selectByID($owner['id']);
$public = publicController($page);
$publishedHtml = $public->index(['slug' => 'example'])->render();
check(str_contains($publishedHtml, 'Draft text') && !str_contains($publishedHtml, 'Changed draft'), 'Published snapshot is rendered instead of newer working content');
check(str_contains($publishedHtml, '<link rel="stylesheet" href="site.css">') && str_contains($publishedHtml, '<style>.quill-scope-'), 'Stylesheet assets and generated scoped CSS reach public output');
$firstUid = array_values($public->getDocument()->getBlocks('main'))[0]->getBlockDescriptor()->getBlockUid();
check(array_values($public->getDocument()->getBlocks('main'))[0]->getBlockDescriptor()->getSourceKey() === 'Quill/page/' . $page->getKey(), 'Stable namespace identifies page across revisions');
check($public->render() === $publishedHtml && array_values($public->getDocument()->getBlocks('main'))[0]->getBlockDescriptor()->getBlockUid() === $firstUid, 'Repeated rendering keeps deterministic IDs and output');

class PublicTestProcessor implements IURPCBlockRenderer
{
    public static int $calls = 0;
    public function __construct(private string $suffix = ' processed') {}
    public function process(IURPCBlock $block, URPC $urpc): void
    {
        self::$calls++;
        $block->setBlockContent($block->getBlockContent() . $this->suffix);
    }
}
$workingBlock = new QuillBlock();
$workingBlock->admin_id = 1;
$workingBlock->setDocument(blockDocument('<Working> & text', PublicTestProcessor::class));
$workingBlock->saveModel();
$workingPage = publicPage(pageDocument($workingBlock->getKey()));
$workingPublic = publicController($workingPage);
$workingPublic->setRendererFactory(PublicTestProcessor::class, (new object\ObjectFactory())->setConstructorArgs(['!']));
check($workingPublic->render() === '<p>&lt;Working&gt; &amp; text!</p>', 'Unpublished page uses working content and real URPC processor/escaping');
check($workingPublic->render() === '<p>&lt;Working&gt; &amp; text!</p>' && PublicTestProcessor::$calls === 2, 'Each render processes an independent document copy');
check($workingBlock->getDocument()->content === '<Working> & text', 'Rendering does not mutate persisted JSON');

$directDocument = new URPCDocument();
$directBlock = new URPCBlock(new URPCBlockDescriptor('h1'));
$directBlock->setBlockContent('Direct <document>');
$directDocument->addBlock('main', $directBlock);
$public->setDocument($directDocument);
check($public->render() === '<h1>Direct &lt;document&gt;</h1>', 'Inherited setDocument overrides page provider and omits stale page assets');
check($directBlock->getBlockDescriptor()->getBlockUid() === '', 'URPC assigns UID on copy rather than mutating supplied source');
check($public->clearDocument() === $public && $public->render() === $publishedHtml, 'Inherited clearDocument resumes page provider');
$customProvider = new class implements IURPCDocumentProvider {
    public int $calls = 0;
    public function createDocument(Context $context): IURPCDocument
    {
        $this->calls++;
        $document = new URPCDocument();
        $block = new URPCBlock(new URPCBlockDescriptor('aside', [], '', 'Application/custom'));
        $block->setBlockContent('Custom provider');
        $document->addBlock('custom', $block);
        return $document;
    }
};
check($public->setDocumentProvider($customProvider) === $public && $public->render() === '<aside>Custom provider</aside>', 'External URPC document providers remain supported without page asset leakage');
check($customProvider->calls === 1, 'Custom provider creates document for current render');
$public->setPage($workingPage);
check(str_contains($public->render(), '&lt;Working&gt;'), 'Changing page replaces prior external provider');

$responsiveBlock = new QuillBlock();
$responsiveBlock->admin_id = 1;
$responsive = blockDocument('Responsive');
$responsive->styles = (object) ['tablet' => (object) ['display' => 'flex']];
$responsiveBlock->setDocument($responsive);
$responsiveBlock->saveModel();
$responsivePage = publicPage(pageDocument($responsiveBlock->getKey()));
$responsiveController = publicController($responsivePage, new QuillToUrpcConverter(['tablet' => 900]));
check(str_contains($responsiveController->render(), '@media (max-width: 900px)'), 'Caller can inject configured converter for responsive styles');
renderingFails(fn () => publicController($responsivePage)->render(), 'block[' . $responsiveBlock->getKey() . ']', 'breakpoint');

$assetsDocument = pageDocument($workingBlock->getKey());
$assetsDocument->assets = (object) ['styles' => ['/site.css?name="test"'], 'scripts' => ['/app.js?a=1&b=2']];
$assetsPage = publicPage($assetsDocument);
$assetHtml = publicController($assetsPage)->render();
check(str_contains($assetHtml, 'href="/site.css?name=&quot;test&quot;"') && str_ends_with($assetHtml, '<script src="/app.js?a=1&amp;b=2" defer></script>'), 'Asset URLs are escaped and scripts follow page content');
check(publicController(publicPage(new stdClass()))->render() === '', 'Newly created {} page has an explicitly empty document');
renderingFails(fn () => publicController(publicPage((object) ['schema_version' => 2, 'sections' => []]))->render(), 'schema_version');
(new database\DatabaseManager())->table('quill_page')->where(['id' => $page->getKey()])->update(['published_snapshot_id' => 999999]);
renderingFails(fn () => publicController($page)->render(), 'page[' . $page->getKey() . ']', 'snapshot[999999]');
(new database\DatabaseManager())->table('quill_page')->where(['id' => $page->getKey()])->update(['published_snapshot_id' => $page->published_snapshot_id]);
(new database\DatabaseManager())->table('quill_page')->where(['id' => $workingPage->getKey()])->update(['published_snapshot_id' => $page->published_snapshot_id]);
renderingFails(fn () => publicController($workingPage)->render(), 'belongs to another page');
(new database\DatabaseManager())->table('quill_page')->where(['id' => $workingPage->getKey()])->update(['published_snapshot_id' => null]);
$newBlock = new QuillBlock();
$newBlock->admin_id = 1;
$newBlock->setDocument(blockDocument('New graph'));
$newBlock->saveModel();
(new database\DatabaseManager())->table('quill_page')->where(['id' => $workingPage->getKey()])->update(['page_json' => json_encode(pageDocument($newBlock->getKey()), JSON_THROW_ON_ERROR)]);
check($workingPublic->render() === '<p>New graph</p>', 'Provider reloads page references when a controller is reused after saving');
(new database\DatabaseManager())->table('quill_page')->where(['id' => $workingPage->getKey()])->update(['page_json' => json_encode(pageDocument($workingBlock->getKey()), JSON_THROW_ON_ERROR)]);

// Execute a real dynamically registered route, then render the returned controller.
$routeRecord = new QuillDynamicRoute();
$routeRecord->path = '/public/{slug}';
$routeRecord->page_id = $workingPage->getKey();
$routeRecord->admin_id = 1;
$routeRecord->saveModel();
$template = publicController($workingPage);
$registered = (new RouteRegistration())->getRoutes($template);
foreach ($registered as $route) {
    if ($route->getPath() !== '/public/{slug}') continue;
    $route->addEventDispatcher($eventDispatcher);
    $view = $route->route($route->match('/public/example'), new router\http\Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/public/example']));
    check($view instanceof PublicPageController && str_contains($view->render(), '&lt;Working&gt;'), 'Dynamic route returns a renderable URPC-compatible page controller');
    (new database\DatabaseManager())->table('quill_page')->where(['id' => $workingPage->getKey()])->update(['deleted_at' => '2026-10-15 12:00:00']);
    renderingFails(fn () => $view->render(), 'page[' . $workingPage->getKey() . ']', 'deleted');
}
$manifest = (new Quill\models\QuillPageSnapshotBlock())->newQuery()->where(['page_snapshot_id' => $page->published_snapshot_id])->firstModel();
(new database\DatabaseManager())->table('quill_block_snapshot')->where(['id' => $manifest->block_snapshot_id])->delete();
renderingFails(fn () => publicController($page)->render(), 'block[' . $manifest->block_id . ']', 'Missing exact snapshot');
check($events->calls >= 10, 'Normal URPC before-render lifecycle runs throughout public rendering');
$actionHtml = publicController($actionPage)->render();
check(str_contains($actionHtml, 'data-quill-action="qa-test"') && str_contains($actionHtml, 'data-quill-target="qt-panel"'), 'Published action bindings render through the actual URPC pipeline');
check(str_contains($actionHtml, 'data-quill-actions') && str_contains($actionHtml, 'quill-actions-runtime.js'), 'Published pages with actions include declarative configuration and runtime');
check(!str_contains($actionHtml, 'Toggle </script> panel') && str_contains($actionHtml, '\\u003C'), 'Action configuration escapes closing script tags in names');
echo 'Passed ' . ($count - $publicChecksStart) . " public page/URPC integration checks.\n";
