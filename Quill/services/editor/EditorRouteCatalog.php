<?php

namespace Quill\services\editor;

use router\interface\IRouterManager;

/** Link destinations from the framework's live registry, including package routes. */
final class EditorRouteCatalog
{
    public function all(?IRouterManager $manager = null): array
    {
        $manager ??= ($GLOBALS['routerManager'] ?? null);
        if (!$manager instanceof IRouterManager) return [];
        $destinations = [];
        foreach ($manager->getRoutes() as $route) {
            if ($route->getMethod() !== 'GET' || $route->isService()) continue;
            $path = $route->getPath();
            if (!str_starts_with($path, '/') || str_starts_with($path, '//')) continue;
            if (isset($destinations[$path])) continue;
            $parameters = self::parameters($path);
            $name = $route->getName();
            $destinations[$path] = [
                'path' => $path,
                'name' => $name !== null && !ctype_digit($name) ? $name : $path,
                'parameters' => array_values(array_unique($parameters)),
            ];
        }
        ksort($destinations, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($destinations);
    }

    public static function parameters(string $path): array
    {
        $parameters = [];
        // Match the framework's SegmentPathMatcher: parameters occupy a whole segment.
        foreach (explode('/', $path) as $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) $parameters[] = trim($segment, '{}');
        }
        return array_values(array_unique($parameters));
    }
}
