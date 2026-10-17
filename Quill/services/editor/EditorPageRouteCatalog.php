<?php

namespace Quill\services\editor;

use Quill\models\QuillDynamicRoute;
use Quill\models\QuillPage;

/** Editable database routes, distinct from framework/package link destinations. */
final class EditorPageRouteCatalog
{
    public function all(): array
    {
        $names = [];
        foreach ((new QuillPage())->newQuery()->getModels() as $page) {
            if ($page->deleted_at === null) $names[$page->getKey()] = $page->name;
        }
        $routes = [];
        foreach ((new QuillDynamicRoute())->newQuery()->getModels() as $route) {
            $routes[] = ['id' => $route->getKey(), 'path' => $route->path, 'page_id' => $route->page_id,
                'page_name' => $names[$route->page_id] ?? null];
        }
        usort($routes, static fn(array $a, array $b): int => strnatcasecmp($a['path'], $b['path']));
        return $routes;
    }
}
