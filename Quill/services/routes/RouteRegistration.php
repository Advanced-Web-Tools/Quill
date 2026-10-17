<?php

namespace Quill\services\routes;

use controller\Controller;
use object\ObjectFactory;
use Quill\controllers\PublicPageController;
use Quill\models\QuillDynamicRoute;
use router\Router;

class RouteRegistration
{
    /** @return list<Router> */
    public function getRoutes(ObjectFactory|PublicPageController|null $controller = null): array
    {
        $controller ??= (new ObjectFactory())->setType(Controller::class)->setClassName(PublicPageController::class);
        $routes = [];
        $records = (new QuillDynamicRoute())->newQuery()->where(['page_id' => null], true)->orderBy('id')->getModels();
        foreach ($records as $record) {
            $page = $record->page;
            // A non-null foreign key can still point to a missing or soft-deleted page.
            if ($page === null || $page->deleted_at !== null) continue;
            $pageController = clone $controller;
            if ($pageController instanceof ObjectFactory) {
                $pageController->addMethodCall('setPage')->addMethodArgs('setPage', [$page]);
            } else {
                $pageController->setPage($page);
            }
            $routes[] = (new Router($record->path, 'index', $pageController))->setMethod('GET');
        }
        return $routes;
    }
}
