<?php

namespace Quill\controllers;

use Dashboard\api\page\DashboardControllerBase;
use DOMException;
use Quill\models\collections\QuillComponentCollection;
use Quill\models\collections\QuillDynamicRouteCollection;
use Quill\models\collections\QuillPageCollection;
use Quill\models\QuillPage;
use Quill\models\QuillComponent;
use Quill\services\conversion\shared\ConversionException;
use response\Response;
use view\View;
use Quill\services\editor\EditorExtensionLoader;

final class QuillController extends DashboardControllerBase
{
    public string $name;
    public string $navigation;
    public string $token;
    public function __construct()
    {
        $this->checkAuth();
    }

    /**
     * @throws DOMException
     */
    public function index(array|string $params): View
    {
        $nav = $this->buildNavigation();
        $params['navigation'] = $nav;
        $params['tab'] = 'Pages';

        $pages = new QuillPageCollection();

        $params['pages'] = array_values(array_filter($pages->obCollection->toArray(), static fn(QuillPage $page): bool => $page->deleted_at === null));
        $params['externalPages'] = array_map(static fn($definition) => $definition->summary(), (new \Quill\services\editor\ExternalResources())->pages($this->eventDispatcher)->all());

        $params['pageTemplates'] = (new \Quill\services\editor\PageLibrary())->templates((new \admin\Admin())->getKey());
        $params['authors'] = $this->authors($params['pages']);
        $params['pageUrls'] = [];
        foreach ((new QuillDynamicRouteCollection())->obCollection->toArray() as $route) {
            if ($route->page_id && !str_contains($route->path, '{') && !isset($params['pageUrls'][$route->page_id])) $params['pageUrls'][$route->page_id] = $route->path;
        }
        return $this->view($params);
    }

    /**
     * @throws DOMException
     */
    public function routes(array $params): View
    {
        $nav = $this->buildNavigation();
        $params['navigation'] = $nav;
        $params['tab'] = 'Routes';

        $routes = new QuillDynamicRouteCollection();
        $params['routes'] = $routes->obCollection->toArray();
        $pages = new QuillPageCollection();
        $params['pages'] = array_values(array_filter(
            $pages->obCollection->toArray(),
            static fn(QuillPage $page): bool => $page->deleted_at === null
        ));
        $params['pageNames'] = [];
        foreach ($params['pages'] as $page) $params['pageNames'][$page->getKey()] = $page->name;

        return $this->view($params);
    }

    /**
     * @throws DOMException
     */
    public function components(array $params): View
    {
        $nav = $this->buildNavigation();
        $params['navigation'] = $nav;
        $params['tab'] = 'Components';

        $components = new QuillComponentCollection();
        $params['components'] = array_values(array_filter($components->obCollection->toArray(), static fn(QuillComponent $component): bool => $component->deleted_at === null));
        $params['authors'] = $this->authors($params['components']);
        $params['externalComponents'] = array_map(static fn($definition) => $definition->summary(), (new \Quill\services\editor\ExternalResources())->components($this->eventDispatcher)->all());

        return $this->view($params);
    }

    /**
     * @throws DOMException
     */
    public function editor(array $params): View|Response
    {
        $type = $params['type'] ?? 'page';
        if (!in_array($type, ['page', 'component'], true)) {
            return Response::make(404, 'Editor not found.')->data([])->asJson();
        }
        $params['tab'] = $type === 'page' ? 'Pages' : 'Components';
        $params['editorType'] = $type;
        if (isset($params['id'])) {
            $id = filter_var($params['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) return Response::make(404, 'Item not found.')->data([])->asJson();
            $item = $type === 'page' ? new QuillPage() : new QuillComponent();
            $item->selectByID($id);
            if ($item->getKey() === null || $item->deleted_at !== null) {
                return Response::make(404, 'Item not found.')->data([])->asJson();
            }
            $params['editorName'] = $item->name;
            $params['editorDescription'] = $item->description;
            $params['editorId'] = $item->getKey();
            $params['editorVersion'] = $item->lock_version;
            $params += $this->prepareEditorDocument($item);
        }
        $params['editorRoutes'] = (new \Quill\services\editor\EditorRouteCatalog())->all();
        $params['editorPageRoutes'] = $type === 'page' ? (new \Quill\services\editor\EditorPageRouteCatalog())->all() : [];
        $params['editorDataSources'] = (new \Quill\services\data\DataSourceCatalog())->all();
        $params['editorDataRoutes'] = [];
        if (isset($item) && $item instanceof QuillPage) foreach ($item->routes() as $route) {
            $params['editorDataRoutes'][] = ['path' => $route->path, 'parameters' => \Quill\services\editor\EditorRouteCatalog::parameters($route->path)];
        }
        $params['editorComponents'] = (new \Quill\services\editor\EditorComponentLibrary())->all();
        $resources = new \Quill\services\editor\ExternalResources();
        $params['editorExternalComponents'] = array_map(static fn($definition) => $definition->summary(), $resources->components($this->eventDispatcher)->all());
        $params['editorExtensions'] = (new EditorExtensionLoader())->load($this->eventDispatcher);
        return $this->view($params);
    }

    public function templates(array $params = []): View
    {
        $params['navigation']=$this->buildNavigation();$params['tab']='Templates';
        $params['templates']=(new \Quill\services\editor\PageLibrary())->templates((new \admin\Admin())->getKey());
        $params['authors']=$this->authors($params['templates']);return $this->view($params);
    }

    public function history(array $params): View|Response
    {
        $id=filter_var($params['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $page=$id===false?null:(new QuillPage())->newQuery()->where(['id'=>$id,'deleted_at'=>null])->firstModel();
        if(!$page)return Response::make(404,'Page not found.')->data([])->asJson();
        $params['navigation']=$this->buildNavigation();$params['tab']='Pages';$params['page']=$page;
        $params['snapshots']=(new \Quill\models\QuillPageSnapshot())->newQuery()->where(['page_id'=>$id])->orderBy('revision','DESC')->getModels();
        $params['authors']=$this->authors($params['snapshots']);return $this->view($params);
    }

    public function preview(array $params): Response
    {
        $this->checkAuth();
        $id=filter_var($params['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $page=$id===false?null:(new QuillPage())->newQuery()->where(['id'=>$id,'deleted_at'=>null])->firstModel();
        if(!$page)return Response::make(404,'Page not found.')->data([])->asJson()->header('Cache-Control','private, no-store');
        try {
            $renderer=new PublicPageController();$renderer->setContext($this->getContext()??new \context\Context(dirname(__DIR__),'Quill','1'));$renderer->setPage($page,null,true);$renderer->eventDispatcher=$this->eventDispatcher;
            $title=htmlspecialchars($page->name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $html='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.$title.' — Draft preview</title></head><body>'.$renderer->render().'</body></html>';
            return Response::make()->file($html)->asHtml()->header('Cache-Control','private, no-store')->header('X-Robots-Tag','noindex, nofollow')->header('X-Frame-Options','SAMEORIGIN');
        } catch(ConversionException|\InvalidArgumentException|\JsonException $error) {
            return Response::make(422,'The draft cannot be previewed. Open it in the editor to review its content.')->data([])->asJson()->header('Cache-Control','private, no-store');
        }
    }

    public function externalPreview(array $params): Response
    {
        $this->checkAuth();
        $definition = (new \Quill\services\editor\ExternalResources())->pages($this->eventDispatcher)->get(rawurldecode($params['key'] ?? ''));
        if (!$definition) return Response::make(404, 'External page unavailable.')->data([])->asJson();
        $renderer = new PublicPageController();
        $renderer->setContext($this->getContext() ?? new \context\Context(dirname(__DIR__), 'Quill', '1'));
        $renderer->eventDispatcher = $this->eventDispatcher;
        $renderer->setSourceNamespace('Quill/external/' . $definition->key);
        $renderer->setDocument($definition->document);
        return Response::make()->file('<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . htmlspecialchars($definition->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title></head><body>' . $renderer->render() . '</body></html>')
            ->asHtml()->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('X-Frame-Options', 'SAMEORIGIN');
    }

    private function authors(array $models): array
    {
        $names=[];
        foreach($models as $model) {
            $id=(int)$model->admin_id;if(isset($names[$id]))continue;
            $author=(new QuillPage())->table('awt_admin')->select(['username'])->where(['id'=>$id])->first();
            $names[$id]=($author['username']??null)?:'Admin #'.$id;
        }
        return $names;
    }

    /** @return array<string, mixed> Read-only bootstrap; document edits remain in the browser. */
    private function prepareEditorDocument(QuillPage|QuillComponent $item): array
    {
        try {
            // Creation currently stores {} until the document receives its first blocks.
            if (get_object_vars($item->getDocument()) === []
                && !($item instanceof QuillPage && $item->source_type === 'urpc')) return [];
            $loaded = (new \Quill\services\editor\EditorDocumentLoader())->load($item);
            return [
                'editorDocumentJson' => json_encode($loaded['document'], JSON_THROW_ON_ERROR),
                'editorCss' => $loaded['css'],
                'editorAssets' => $loaded['assets'],
            ];
        } catch (ConversionException|\JsonException|\UnexpectedValueException|\InvalidArgumentException $error) {
            return ['editorError' => $error->getMessage()];
        }
    }

}
