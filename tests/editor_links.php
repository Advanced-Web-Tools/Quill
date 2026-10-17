<?php
// The real framework registry is the source of the editor's route list.
ob_start();
require __DIR__ . '/editor_save.php';

use object\ObjectFactory;
use Quill\controllers\PublicPageController;
use Quill\services\editor\EditorRouteCatalog;
use router\manager\RouterManager;
use router\Router;

$manager = new RouterManager();
$controllerFactory = (new ObjectFactory())->setClassName(PublicPageController::class);
foreach ([
    (new Router('/', 'index', clone $controllerFactory))->setName('Home'),
    (new Router('/products/{slug}', 'index', clone $controllerFactory))->setName('Product'),
    (new Router('/contact', 'index', clone $controllerFactory))->setName('Contact'),
    (new Router('/submit', 'index', clone $controllerFactory))->setMethod('POST'),
    (new Router('/service', 'index', clone $controllerFactory, true)),
    (new Router('/contact', 'index', clone $controllerFactory))->setName('Duplicate'),
    (new Router('//outside.example', 'index', clone $controllerFactory)),
    (new Router('/pair/{id}/{id}', 'index', clone $controllerFactory)),
] as $route) $manager->addRouter($route);
$catalog = new EditorRouteCatalog();
$routes = $catalog->all($manager);
check(array_column($routes, 'path') === ['/', '/contact', '/pair/{id}/{id}', '/products/{slug}'], 'Link destinations use unique local GET routes, excluding services and external hosts');
check($routes[3]['parameters'] === ['slug'] && $routes[2]['parameters'] === ['id'], 'Framework segment parameters are exported once');
check($routes[0]['name'] === 'Home' && $routes[2]['name'] === '/pair/{id}/{id}', 'Named routes have readable titles and unnamed routes use their paths');
$GLOBALS['routerManager'] = $manager;
check($catalog->all() === $routes, 'Default discovery uses the live bootstrap router manager');

$catalogErrors = libxml_use_internal_errors(true);
$catalogView = $viewController->editor(['type' => 'page', 'id' => (string) $owner['id']]);
libxml_clear_errors(); libxml_use_internal_errors($catalogErrors);
check($catalogView->bundle['editorRoutes'] === $routes, 'Authenticated editor bootstrap receives the framework route catalog');
unset($GLOBALS['routerManager']);
check($catalog->all() === [], 'An environment without a router registry still supports custom links');
$linkOwner = request('page', ['_token' => 'test-csrf', 'name' => 'Link destinations'], 201);
$hrefs = ['mailto:hello@example.com?subject=Hello%20there', 'tel:+381641234567', 'sms:+381641234567', 'webcal://example.com/events.ics', 'https://example.com/event.ics', 'https://wa.me/381641234567', 'https://t.me/quill', 'spotify:track:123', '/products/%C4%8Caj?sort=price#details', '#contact'];
$linkBlocks = [];
foreach ($hrefs as $href) {
    $link = $element('a', 'Open destination');
    $link['attributes'] = (object) ['href' => $href, 'target' => '_blank', 'rel' => 'sponsored noopener'];
    $linkBlocks[] = $link;
}
$linkBlocks[4]['attributes']->download = 'event.ics';
$linkDocument = ['schema_version' => 1, 'sections' => [['name' => 'main', 'blocks' => $linkBlocks]]];
$linkSaved = documentRequest(['type' => 'page', 'id' => (string) $linkOwner['id']], array_replace($post, ['document' => json_encode($linkDocument), 'mode' => 'publish', 'lock_version' => '1']), 200);
$linkSnapshot = new \Quill\models\QuillPageSnapshot(); $linkSnapshot->selectByID($linkSaved['snapshot_id']);
$linkResult = (new \Quill\services\conversion\quillToUrpc\QuillToUrpcConverter())->convert(new \Quill\services\conversion\quillToUrpc\SnapshotQuillContentSource($linkSnapshot));
check(array_map(static fn ($block) => $block->getBlockAttributes()['href'], $linkResult->document->getBlocks('main')) === $hrefs, 'All destination schemes and encoded route URLs survive Save/Publish into public output');
$publishedAttributes = $linkResult->document->getBlocks('main')[4]->getBlockAttributes();
check($publishedAttributes['target'] === '_blank' && $publishedAttributes['rel'] === 'sponsored noopener' && $publishedAttributes['download'] === 'event.ics', 'Opening preferences and filenames survive public export');
echo ob_get_clean();
echo "Passed editor link catalog and publishing checks; $count total checks.\n";
