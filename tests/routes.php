<?php
// Run: php tests/routes.php. Uses real models and routing with an isolated SQLite database.
require __DIR__ . '/save.php';

use Quill\controllers\PublicPageController;
use Quill\models\QuillDynamicRoute;
use Quill\services\routes\RouteRegistration;

foreach ((new QuillDynamicRoute())->newQuery()->getModels() as $record) $record->deleteModel();
$firstPage = request('page', ['_token' => 'test-csrf', 'name' => 'Dynamic page'], 201);
$secondPage = request('page', ['_token' => 'test-csrf', 'name' => 'Root page'], 201);
request('route', ['_token' => 'test-csrf', 'page_id' => (string) $firstPage['id'], 'segments' => [
    ['type' => 'part', 'value' => 'products'], ['type' => 'param', 'value' => 'slug'],
]], 201);
request('route', ['_token' => 'test-csrf', 'page_id' => (string) $secondPage['id'], 'segments' => []], 201);
foreach ([['/unassigned', null], ['/missing-page', 99999]] as [$path, $pageId]) {
    $record = new QuillDynamicRoute();
    $record->path = $path;
    $record->page_id = $pageId;
    $record->admin_id = 1;
    $record->saveModel();
}
$deletedPage = request('page', ['_token' => 'test-csrf', 'name' => 'Deleted page'], 201);
request('route', ['_token' => 'test-csrf', 'page_id' => (string) $deletedPage['id'], 'segments' => [['type' => 'part', 'value' => 'deleted-page']]], 201);
(new database\DatabaseManager())->table('quill_page')->where(['id' => $deletedPage['id']])->update(['deleted_at' => '2026-10-15 12:00:00']);

$registration = new RouteRegistration();
$routes = $registration->getRoutes();
check(array_map(static fn ($route) => $route->getPath(), $routes) === ['/products/{slug}', '/'], 'Only routes with existing non-deleted pages register, in stored order');
check($routes[0]->match('/products/example') === ['slug' => 'example'], 'Dynamic route keeps captured parameters');
check($routes[0]->getMethod() === 'GET' && $routes[0]->getAction() === 'index', 'Public route uses GET/index');
$firstController = $routes[0]->getController()->create();
$secondController = $routes[1]->getController()->create();
check($firstController instanceof PublicPageController && $firstController->page->getKey() === $firstPage['id'], 'Controller receives the route page');
check($secondController instanceof PublicPageController && $secondController->page->getKey() === $secondPage['id'], 'Different routes receive independent page bindings');
check($firstController !== $secondController, 'Routes use separate controller instances');
$template = new PublicPageController();
$instanceRoutes = $registration->getRoutes($template);
check($template->page === null && $instanceRoutes[0]->getController()->page->getKey() === $firstPage['id'], 'Instance registration clones its template before binding page');
check(count($registration->getRoutes()) === 2, 'Repeated registration does not accumulate routes');
foreach ((new QuillDynamicRoute())->newQuery()->getModels() as $record) $record->deleteModel();
check($registration->getRoutes() === [], 'No saved routes yields an empty list');
echo "Passed dynamic route registration checks; $count total model/controller/route checks.\n";
