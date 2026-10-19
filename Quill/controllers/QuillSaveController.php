<?php

namespace Quill\controllers;

use admin\Admin;
use InvalidArgumentException;
use Quill\models\QuillComponent;
use Quill\models\QuillDynamicRoute;
use Quill\models\QuillModel;
use Quill\models\QuillPage;
use response\Response;
use router\security\Csrf;
use Throwable;
use Quill\services\editor\EditorDocumentSaver;
use Quill\services\conversion\shared\ConversionInput;
use Quill\services\conversion\shared\ConversionException;

final class QuillSaveController extends QuillControllerBase
{
    public function index(array|string $params): Response
    {
        $this->checkAuth();
        return $this->respond(405, 'Use a creation endpoint.');
    }

    public function page(array|string $params = []): Response
    {
        return $this->create('page');
    }

    public function component(array|string $params = []): Response
    {
        return $this->create('component');
    }

    public function route(array|string $params = []): Response
    {
        return $this->create('route');
    }

    public function updateTemplate(array|string $params = []): Response { return $this->manage('template', false); }
    public function deleteTemplate(array|string $params = []): Response { return $this->manage('template', true); }

    public function updatePage(array|string $params = []): Response { return $this->manage('page', false); }
    public function updateComponent(array|string $params = []): Response { return $this->manage('component', false); }
    public function updateRoute(array|string $params = []): Response { return $this->manage('route', false); }
    public function deletePage(array|string $params = []): Response { return $this->manage('page', true); }
    public function deleteComponent(array|string $params = []): Response { return $this->manage('component', true); }
    public function deleteRoute(array|string $params = []): Response { return $this->manage('route', true); }

    public function saveDocument(array|string $params = []): Response
    {
        $this->checkAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return $this->respond(405, 'Use POST to save a document.')->header('Allow', 'POST');
        if (!(new Csrf())->validateToken($_POST['_token'] ?? null)) return $this->respond(403, 'Your session token expired. Reload and try again.');
        try {
            if (!is_array($params) || !in_array($params['type'] ?? null, ['page', 'component'], true)) return $this->respond(404, 'Document not found.');
            $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) return $this->respond(404, 'Document not found.');
            if (array_diff(array_keys($_POST), ['_token', 'document', 'mode', 'lock_version', 'source_revision'])) throw new InvalidArgumentException('Unexpected document fields.');
            $version = filter_var($_POST['lock_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $json = $_POST['document'] ?? null;
            $mode = $_POST['mode'] ?? 'draft';
            if ($version === false) throw new InvalidArgumentException('Invalid lock_version. Expected a positive integer document version.');
            if (!is_string($json)) throw new InvalidArgumentException('Document must be a JSON string.');
            if (strlen($json) > 5 * 1024 * 1024) throw new InvalidArgumentException('Document exceeds the 5 MB limit.');
            if (!is_string($mode)) throw new InvalidArgumentException('Invalid save mode. Expected draft or publish.');
            $owner = $params['type'] === 'page' ? new QuillPage() : new QuillComponent();
            $owner->selectByID($id);
            if ($owner->getKey() === null || $owner->deleted_at !== null) return $this->respond(404, 'Document not found.');
            $input = ConversionInput::decode($json, 'document');
            if ($owner instanceof QuillPage && $owner->source_type === 'urpc') {
                $revision = $_POST['source_revision'] ?? null;
                if (!is_string($revision)) throw new InvalidArgumentException('Reload the external page before saving its changes.');
                $result = (new \Quill\services\editor\ExternalPageOverrides($this->eventDispatcher))->save($owner, $input, $version, $mode, (new Admin())->getKey(), $revision);
            } else $result = (new EditorDocumentSaver())->save($owner, $input, $version, $mode, (new Admin())->getKey());
            return $this->respond(200, $mode === 'publish' ? 'Published.' : 'Draft saved.', $result);
        } catch (InvalidArgumentException|ConversionException|\JsonException $error) {
            return $this->respond(422, $error->getMessage());
        } catch (\DomainException $error) {
            return $this->respond(409, $error->getMessage());
        } catch (Throwable $error) {
            error_log('Quill document save failed: ' . $error->getMessage());
            return $this->respond(500, 'Unable to save the document. Please try again.');
        }
    }

    public function blockHistory(array|string $params = []): Response
    {
        $this->checkAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') return $this->respond(405, 'Use GET.')->header('Allow', 'GET');
        try {
            if (!is_array($params) || !in_array($params['type'] ?? null, ['page', 'component'], true)) return $this->respond(404, 'Document not found.');
            $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) return $this->respond(404, 'Document not found.');
            $owner = $params['type'] === 'page' ? new QuillPage() : new QuillComponent();
            $owner->selectByID($id);
            if ($owner->getKey() === null || $owner->deleted_at !== null) return $this->respond(404, 'Document not found.');
            $uid = $_GET['uid'] ?? null;
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_string($uid) || $page === false) throw new InvalidArgumentException('Invalid history request.');
            return $this->respond(200, 'Block history loaded.', (new \Quill\services\editor\BlockVersionHistory())->listing($owner, $uid, $page));
        } catch (InvalidArgumentException $error) { return $this->respond(422, $error->getMessage()); }
        catch (Throwable $error) {
            error_log('Quill block history failed: ' . $error->getMessage());
            return $this->respond(500, 'Unable to load block history. Please try again.');
        }
    }

    public function pageSnapshotHistory(array|string $params = []): Response
    {
        $this->checkAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') return $this->respond(405, 'Use GET.')->header('Allow', 'GET');
        try {
            if (!is_array($params) || ($params['type'] ?? null) !== 'page') throw new \OutOfBoundsException('Page not found.');
            $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new \OutOfBoundsException('Page not found.');
            $owner = (new QuillPage())->newQuery()->where(['id' => $id, 'deleted_at' => null])->firstModel()
                ?? throw new \OutOfBoundsException('Page not found.');
            if (\Quill\services\editor\ExternalPageOverrides::isComponent($owner)) throw new \OutOfBoundsException('Page not found.');
            $history = new \Quill\services\editor\PageSnapshotHistory();
            if (isset($_GET['snapshot'])) {
                $snapshot = filter_var($_GET['snapshot'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($snapshot === false) throw new InvalidArgumentException('Invalid snapshot.');
                $data = $history->document($owner, $snapshot);
            } else {
                $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($page === false) throw new InvalidArgumentException('Invalid history page.');
                $data = $history->listing($owner, $page);
            }
            return $this->respond(200, 'Page snapshots loaded.', $data);
        } catch (\OutOfBoundsException $error) { return $this->respond(404, $error->getMessage()); }
        catch (InvalidArgumentException|ConversionException|\JsonException $error) { return $this->respond(422, $error->getMessage()); }
        catch (\DomainException $error) { return $this->respond(409, $error->getMessage()); }
        catch (Throwable $error) {
            error_log('Quill page snapshot history failed: ' . $error->getMessage());
            return $this->respond(500, 'Unable to load page snapshots. Please try again.');
        }
    }

    public function editorPalettes(array|string $params = []): Response { return $this->resourceLibraryRequest($params, 'read', 'palette'); }
    public function saveEditorPalette(array|string $params = []): Response { return $this->resourceLibraryRequest($params, 'save', 'palette'); }
    public function deleteEditorPalette(array|string $params = []): Response { return $this->resourceLibraryRequest($params, 'delete', 'palette'); }

    public function editorAnimations(array|string $params = []): Response { return $this->resourceLibraryRequest($params, 'read'); }
    public function saveEditorAnimation(array|string $params = []): Response { return $this->resourceLibraryRequest($params, 'save'); }
    public function deleteEditorAnimation(array|string $params = []): Response { return $this->resourceLibraryRequest($params, 'delete'); }

    private function resourceLibraryRequest(array|string $params, string $operation, string $resource = 'animation'): Response
    {
        $this->checkAuth();
        $method = $operation === 'read' ? 'GET' : 'POST';
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) return $this->respond(405, 'Use ' . $method . '.')->header('Allow', $method);
        if ($method === 'POST' && !(new Csrf())->validateToken($_POST['_token'] ?? null)) return $this->respond(403, 'Your session token expired. Reload and try again.');
        try {
            $library = $resource === 'animation' ? new \Quill\services\animations\AnimationLibrary() : new \Quill\services\palettes\ColorPaletteLibrary();
            $plural = $resource === 'animation' ? 'animations' : 'palettes';
            $label = $resource === 'animation' ? 'Animation' : 'Palette';
            $id = is_array($params) && isset($params['id']) ? filter_var($params['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
            if ($id === false) return $this->respond(404, $label . ' not found.');
            if ($operation === 'read') {
                if ($id === null) {
                    $data = [$plural => $library->all()];
                    if ($resource === 'palette') $data['externalPalettes'] = array_map(static fn($definition) => $definition->summary(), (new \Quill\services\editor\ExternalResources())->palettes($this->eventDispatcher)->all());
                    return $this->respond(200, $label . ' library loaded.', $data);
                }
                $item = $library->find($id) ?? throw new \OutOfBoundsException($label . ' not found.');
                return $this->respond(200, $label . ' loaded.', [$resource => $library->summary($item) + ['definition' => $item->getDefinition()]]);
            }
            if (array_diff(array_keys($_POST), ['_token', 'definition', 'lock_version'])) throw new InvalidArgumentException('Unexpected library fields.');
            $version = $id === null ? null : filter_var($_POST['lock_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($version === false) throw new InvalidArgumentException('Provide the resource version.');
            if ($operation === 'delete') {
                if ($id === null) throw new InvalidArgumentException('Provide a resource ID.');
                $library->delete($id, $version);
                return $this->respond(200, $resource === 'animation' ? 'Animation deleted. References will be skipped when rendering.' : 'Palette deleted. Existing element colors are unchanged.');
            }
            $json = $_POST['definition'] ?? null;
            if (!is_string($json) || strlen($json) > 2000000) throw new InvalidArgumentException('Provide a definition up to 2 MB.');
            $saved = $library->save(ConversionInput::decode($json, $resource), (new Admin())->getKey(), $id, $version);
            return $this->respond($id === null ? 201 : 200, $label . ' saved.', [$resource => $saved]);
        } catch (\OutOfBoundsException $error) { return $this->respond(404, $error->getMessage()); }
        catch (InvalidArgumentException|ConversionException|\JsonException $error) { return $this->respond(422, $error->getMessage()); }
        catch (\DomainException $error) { return $this->respond(409, $error->getMessage()); }
        catch (Throwable $error) { error_log('Quill library request failed: ' . $error->getMessage()); return $this->respond(500, 'Unable to access the library. Please try again.'); }
    }

    public function editorComponent(array|string $params = []): Response
    {
        $this->checkAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') return $this->respond(405, 'Use GET to load a component.')->header('Allow', 'GET');
        $id = filter_var(is_array($params) ? ($params['id'] ?? null) : null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) return $this->respond(404, 'Component not found.');
        $component = new QuillComponent(); $component->selectByID($id);
        if ($component->getKey() === null || $component->deleted_at !== null) return $this->respond(404, 'Component not found.');
        try {
            return $this->respond(200, 'Component loaded.', ['document' => (new \Quill\services\editor\EditorComponentLibrary())->document($component), 'component' => ['id' => $component->getKey(), 'name' => $component->name, 'version' => $component->lock_version]]);
        } catch (InvalidArgumentException|ConversionException|\JsonException|\UnexpectedValueException $error) {
            return $this->respond(422, $error->getMessage());
        }
    }

    public function externalEditorComponent(array|string $params = []): Response
    {
        $this->checkAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') return $this->respond(405, 'Use GET.')->header('Allow', 'GET');
        try {
            $resources = new \Quill\services\editor\ExternalResources();
            $key = rawurldecode(is_array($params) ? ($params['key'] ?? '') : '');
            $definition = $resources->components($this->eventDispatcher)->get($key) ?? throw new \OutOfBoundsException('External component unavailable.');
            return $this->respond(200, 'External component loaded.', ['component' => $definition->summary() + ['version' => (new \Quill\services\editor\ExternalPageOverrides($this->eventDispatcher))->find($definition->key,'component')?->published_snapshot_id ?? 1], 'document' => (new \Quill\services\editor\ExternalPageOverrides($this->eventDispatcher))->publishedComponentDocument($definition)]);
        } catch (\OutOfBoundsException $error) { return $this->respond(404, $error->getMessage()); }
        catch (InvalidArgumentException|ConversionException|\JsonException $error) { return $this->respond(422, $error->getMessage()); }
    }

    public function openExternalComponent(array|string $params = []): Response
    {
        $this->checkAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return $this->respond(405, 'Use POST.')->header('Allow', 'POST');
        if (!(new Csrf())->validateToken($_POST['_token'] ?? null)) return $this->respond(403, 'Your session token expired. Reload and try again.');
        try {
            $key=$_POST['key']??null;
            if(!is_string($key)||array_diff(array_keys($_POST),['_token','key']))throw new InvalidArgumentException('Choose an external component.');
            $resources=new \Quill\services\editor\ExternalResources();
            $definition=$resources->components($this->eventDispatcher)->get($key)??throw new \OutOfBoundsException('External component unavailable.');
            $owner=(new \Quill\services\editor\ExternalPageOverrides($this->eventDispatcher))->open($definition,(new Admin())->getKey(),'component');
            return $this->respond(200,'External component opened. Only changed UID blocks will be saved.',['editorUrl'=>'/quill/editor/page/'.$owner->getKey()]);
        } catch(\OutOfBoundsException $error){return $this->respond(404,$error->getMessage());}
        catch(InvalidArgumentException|ConversionException|\JsonException $error){return $this->respond(422,$error->getMessage());}
        catch(Throwable $error){error_log('Quill external component open failed: '.$error->getMessage());return $this->respond(500,'Unable to open the external component.');}
    }

    public function importExternalPage(array|string $params = []): Response
    {
        $this->checkAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return $this->respond(405, 'Use POST.')->header('Allow', 'POST');
        if (!(new Csrf())->validateToken($_POST['_token'] ?? null)) return $this->respond(403, 'Your session token expired. Reload and try again.');
        try {
            $key = $_POST['key'] ?? null;
            if (!is_string($key) || array_diff(array_keys($_POST), ['_token', 'key'])) throw new InvalidArgumentException('Choose an external page.');
            $resources = new \Quill\services\editor\ExternalResources();
            $definition = $resources->pages($this->eventDispatcher)->get($key) ?? throw new \OutOfBoundsException('External page unavailable.');
            $page = (new \Quill\services\editor\ExternalPageOverrides($this->eventDispatcher))->open($definition, (new Admin())->getKey());
            return $this->respond(200, 'External page opened. Only changed UID blocks will be saved.', ['editorUrl' => '/quill/editor/page/' . $page->getKey()]);
        } catch (\OutOfBoundsException $error) { return $this->respond(404, $error->getMessage()); }
        catch (InvalidArgumentException|ConversionException|\JsonException $error) { return $this->respond(422, $error->getMessage()); }
        catch (Throwable $error) { error_log('Quill external import failed: ' . $error->getMessage()); return $this->respond(500, 'Unable to open the external page.'); }
    }

    public function detachedEditorComponent(array|string $params = []): Response
    {
        $this->checkAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') return $this->respond(405, 'Use GET to prepare a local block.')->header('Allow', 'GET');
        $id = filter_var(is_array($params) ? ($params['id'] ?? null) : null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id === false) return $this->respond(404, 'Component not found.');
        $component = new QuillComponent(); $component->selectByID($id);
        if ($component->getKey() === null || $component->deleted_at !== null) return $this->respond(404, 'Component not found.');
        $version = filter_var($_GET['version'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($version === false || $version !== $component->lock_version) return $this->respond(409, 'This component changed. Refresh its preview and try again.');
        try {
            $instance = $_GET['instance'] ?? null;
            if (!is_string($instance)) throw new InvalidArgumentException('Choose a component instance.');
            $prepared = (new \Quill\services\editor\DetachedComponentDocument())->create($component, $instance);
            return $this->respond(200, 'Local block prepared.', [...$prepared,'component'=>['id'=>$id,'version'=>$component->lock_version]]);
        } catch (InvalidArgumentException|ConversionException|\JsonException|\UnexpectedValueException $error) {
            return $this->respond(422, $error->getMessage());
        }
    }

    public function createEditorComponent(array|string $params = []): Response
    {
        $this->checkAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return $this->respond(405, 'Use POST to create a component.')->header('Allow', 'POST');
        if (!(new Csrf())->validateToken($_POST['_token'] ?? null)) return $this->respond(403, 'Your session token expired. Reload and try again.');
        try {
            if (array_diff(array_keys($_POST), ['_token', 'name', 'description', 'document'])) throw new InvalidArgumentException('Unexpected component fields.');
            $fields = $this->documentFields();
            $json = $_POST['document'] ?? null;
            if (!is_string($json) || strlen($json) > 5 * 1024 * 1024) throw new InvalidArgumentException('Provide a component document up to 5 MB.');
            $component = (new \Quill\services\editor\EditorComponentLibrary())->create($fields['name'], $fields['description'], ConversionInput::decode($json, 'document'), (new Admin())->getKey());
            return $this->respond(201, 'Component saved.', ['component' => $component]);
        } catch (InvalidArgumentException|ConversionException|\JsonException $error) {
            return $this->respond(422, $error->getMessage());
        } catch (Throwable $error) {
            error_log('Quill component creation failed: ' . $error->getMessage());
            return $this->respond(500, 'Unable to save the component. Please try again.');
        }
    }

    public function savePageTemplate(array $params): Response { return $this->pageOperation([...$params,'operation'=>'template']); }
    public function restorePageSnapshot(array $params): Response { return $this->pageOperation([...$params,'operation'=>'restore']); }
    public function deletePageSnapshot(array $params): Response { return $this->pageOperation([...$params,'operation'=>'delete-snapshot']); }

    private function pageOperation(array|string $params = []): Response
    {
        $this->checkAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return $this->respond(405, 'Use POST.')->header('Allow','POST');
        if (!(new Csrf())->validateToken($_POST['_token'] ?? null)) return $this->respond(403, 'Your session token expired. Reload and try again.');
        try {
            $operation=is_array($params)?($params['operation']??''):'';
            if(!in_array($operation,['template','restore','delete-snapshot'],true))return $this->respond(404,'Operation not found.');
            $id=filter_var($params['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $version=filter_var($_POST['lock_version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id===false||$version===false)throw new InvalidArgumentException('Provide a valid page and version.');
            $allowed=$operation==='template'?['_token','lock_version','name','description']:['_token','lock_version'];
            if(array_diff(array_keys($_POST),$allowed))throw new InvalidArgumentException('Unexpected form fields.');
            $page=(new QuillPage())->newQuery()->where(['id'=>$id,'deleted_at'=>null])->firstModel()??throw new \OutOfBoundsException('Page not found.');
            $library=new \Quill\services\editor\PageLibrary();
            if($operation==='template') {
                $fields=$this->documentFields();$template=$library->saveTemplate($page,$fields['name'],$fields['description'],$version,(new Admin())->getKey());
                return $this->respond(201,'Template saved privately.',['id'=>$template->getKey()]);
            }
            $snapshotId=filter_var($params['snapshotId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($snapshotId===false)throw new \OutOfBoundsException('Version not found.');
            $snapshot=(new \Quill\models\QuillPageSnapshot())->newQuery()->where(['id'=>$snapshotId,'page_id'=>$id])->firstModel()??throw new \OutOfBoundsException('Version not found.');
            if($operation==='restore')$library->restore($page,$snapshot,$version,(new Admin())->getKey());else $library->deleteSnapshot($page,$snapshot,$version);
            return $this->respond(200,$operation==='restore'?'Version restored as draft. The live page is unchanged.':'Version deleted.');
        } catch(\OutOfBoundsException $error) { return $this->respond(404,$error->getMessage()); }
        catch(InvalidArgumentException|ConversionException|\JsonException $error) { return $this->respond(422,$error->getMessage()); }
        catch(\DomainException $error) { return $this->respond(409,$error->getMessage()); }
        catch(Throwable $error) { error_log('Quill page operation failed: '.$error->getMessage());return $this->respond(500,'Unable to complete this operation. Please try again.'); }
    }

    private function manage(string $type, bool $delete): Response
    {
        $this->checkAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return $this->respond(405, 'Use POST to manage an item.')->header('Allow', 'POST');
        }
        if (!(new Csrf())->validateToken($_POST['_token'] ?? null)) {
            return $this->respond(403, 'Your session token expired. Reload the page and try again.');
        }
        try {
            $allowed = ['_token', 'id'];
            if ($type !== 'route') $allowed[] = 'lock_version';
            if (!$delete) $allowed = array_merge($allowed, $type === 'route' ? ['segments', 'page_id'] : ['name', 'description']);
            if (array_diff(array_keys($_POST), $allowed)) throw new InvalidArgumentException('Unexpected form fields.');
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new InvalidArgumentException('Invalid item ID.');
            $model = match ($type) {
                'page' => new QuillPage(),
                'component' => new QuillComponent(),
                'route' => new QuillDynamicRoute(),
                'template' => new \Quill\models\QuillPageTemplate(),
            };
            $model->selectByID($id);
            if ($type === 'template' && $model->admin_id !== (new Admin())->getKey()) return $this->respond(404, 'Template not found.');
            if ($model->getKey() === null || ($type !== 'route' && $model->deleted_at !== null)) {
                return $this->respond(404, 'This item is no longer available.');
            }
            $where = ['id' => $id];
            if ($type !== 'route') {
                $version = filter_var($_POST['lock_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($version === false || $version !== $model->lock_version) {
                    throw new \DomainException('This item changed. Reload the page before trying again.');
                }
                $where['lock_version'] = $version;
            }
            if ($delete && $type === 'page' && (new QuillDynamicRoute())->newQuery()->where(['page_id' => $id])->getModels() !== []) {
                throw new \DomainException('This page has routes. Reassign or delete those routes first.');
            }
            if ($delete && $type === 'route') {
                $changed = $model->deleteModel();
            } else {
                if ($delete) {
                    $changes = ['deleted_at' => date('Y-m-d H:i:s')];
                    if ($type === 'page') { $changes['status'] = 'draft'; if ($model->source_type === 'urpc') $changes['source_key'] = null; }
                } elseif ($type === 'route') {
                    $route = $this->newRoute($id);
                    $changes = ['path' => $route->path, 'page_id' => $route->page_id];
                } else {
                    $changes = $this->documentFields();
                }
                $changes['updated_at'] = date('Y-m-d H:i:s');
                if ($type !== 'route') $changes['lock_version'] = $version + 1;
                $changed = $model->table($model->model_source)->where($where)->update($changes);
                // An unchanged route can report zero affected rows on MySQL.
                if (!$changed && $type === 'route' && !$delete) {
                    $current = new QuillDynamicRoute();
                    $current->selectByID($id);
                    $changed = $current->getKey() !== null && $current->path === $changes['path'] && $current->page_id === $changes['page_id'];
                }
            }
            if (!$changed) throw new \DomainException('This item changed. Reload the page before trying again.');
            return $this->respond(200, $delete ? 'Deleted successfully.' : 'Saved successfully.', ['id' => $id]);
        } catch (InvalidArgumentException $error) {
            return $this->respond(422, $error->getMessage());
        } catch (\DomainException $error) {
            return $this->respond(409, $error->getMessage());
        } catch (Throwable $error) {
            error_log('Quill management failed: ' . $error->getMessage());
            return $this->respond(500, 'Unable to save changes. Please try again.');
        }
    }

    private function create(string $type): Response
    {
        $this->checkAuth();
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return $this->respond(405, 'Use POST to create an item.')->header('Allow', 'POST');
        }
        if (!(new Csrf())->validateToken($_POST['_token'] ?? null)) {
            return $this->respond(403, 'Your session token expired. Reload the page and try again.');
        }

        try {
            $allowed = $type === 'route'
                ? ['_token', 'segments', 'page_id']
                : ['_token', 'name', 'description', ...($type === 'page' ? ['template_id'] : [])];
            if (array_diff(array_keys($_POST), $allowed)) {
                throw new InvalidArgumentException('Unexpected form fields.');
            }

            if ($type === 'page' && ($_POST['template_id'] ?? '') !== '') {
                $templateId=filter_var($_POST['template_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if($templateId===false)throw new InvalidArgumentException('Choose a valid template.');
                $fields=$this->documentFields();
                $page=(new \Quill\services\editor\PageLibrary())->createFromTemplate($templateId,(new Admin())->getKey(),$fields['name'],$fields['description']);
                return $this->respond(201,'Page created from template.',['id'=>$page->getKey(),'editorUrl'=>'/quill/editor/page/'.$page->getKey()]);
            }
            $model = $type === 'route' ? $this->newRoute() : $this->newDocument($type);
            $model->admin_id = (new Admin())->getKey();
            $id = $model->saveModel();
            if ($id === null) {
                throw new \RuntimeException('Insert did not return an ID.');
            }
            return $this->respond(201, 'Created successfully.', ['id' => $id]);
        } catch (\OutOfBoundsException $error) {
            return $this->respond(404, $error->getMessage());
        } catch (InvalidArgumentException|ConversionException|\JsonException $error) {
            return $this->respond(422, $error->getMessage());
        } catch (Throwable $error) {
            error_log('Quill creation failed: ' . $error->getMessage());
            return $this->respond(500, 'Unable to save. Please try again.');
        }
    }

    private function newDocument(string $type): QuillModel
    {
        $fields = $this->documentFields();
        $model = $type === 'page' ? new QuillPage() : new QuillComponent();
        $model->name = $fields['name'];
        $model->description = $fields['description'];
        $model->setDocument(new \stdClass());
        if ($model instanceof QuillPage) {
            $model->status = 'draft';
            $model->source_type = 'quill';
        }
        return $model;
    }

    private function documentFields(): array
    {
        $name = $this->text($_POST['name'] ?? null, 'Name', 255);
        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        $description = $this->text($_POST['description'] ?? '', 'Description', 65535);
        return ['name' => $name, 'description' => $description === '' ? null : $description];
    }

    private function newRoute(?int $currentId = null): QuillDynamicRoute
    {
        $path = $this->assemblePath($_POST['segments'] ?? []);
        foreach ((new QuillDynamicRoute())->newQuery()->where(['path' => $path])->getModels() as $existing) {
            if ($existing->getKey() !== $currentId) throw new InvalidArgumentException('A route with this path already exists.');
        }
        $pageId = $_POST['page_id'] ?? '';
        if (!is_string($pageId) || ($pageId !== '' && !preg_match('/^[1-9][0-9]*$/D', $pageId))) {
            throw new InvalidArgumentException('Choose a valid page.');
        }
        $id = $pageId === '' ? null : filter_var($pageId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException('Choose a valid page.');
        }
        if ($id !== null) {
            $page = new QuillPage();
            $page->selectByID($id);
            if ($page->getKey() === null || $page->deleted_at !== null || \Quill\services\editor\ExternalPageOverrides::isComponent($page)) {
                throw new InvalidArgumentException('The selected page is no longer available.');
            }
        }
        $route = new QuillDynamicRoute();
        $route->path = $path;
        $route->page_id = $id;
        return $route;
    }

    private function assemblePath(mixed $segments): string
    {
        if (!is_array($segments) || !array_is_list($segments) || count($segments) > 64) {
            throw new InvalidArgumentException('Provide up to 64 ordered route parts or parameters.');
        }
        $parts = [];
        $parameters = [];
        foreach ($segments as $segment) {
            if (!is_array($segment) || array_diff(array_keys($segment), ['type', 'value'])) {
                throw new InvalidArgumentException('Invalid route part.');
            }
            $value = $this->text($segment['value'] ?? null, 'Route part', 512);
            if (($segment['type'] ?? null) === 'param') {
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value)) {
                    throw new InvalidArgumentException('Parameter names must start with a letter or underscore and contain only letters, numbers, or underscores.');
                }
                if (in_array($value, $parameters, true)) {
                    throw new InvalidArgumentException('Parameter names must be unique within a route.');
                }
                $parameters[] = $value;
                $parts[] = '{' . $value . '}';
            } elseif (($segment['type'] ?? null) === 'part') {
                if (!preg_match('/^[A-Za-z0-9._~-]+$/D', $value) || in_array($value, ['.', '..'], true)) {
                    throw new InvalidArgumentException('Parts must contain URL letters, numbers, dots, underscores, tildes, or hyphens, without slashes.');
                }
                $parts[] = $value;
            } else {
                throw new InvalidArgumentException('Choose a part or parameter.');
            }
        }
        $path = '/' . implode('/', $parts);
        if (strlen($path) > 512) {
            throw new InvalidArgumentException('The assembled path must be no longer than 512 characters.');
        }
        return $path;
    }

    private function text(mixed $value, string $label, int $max): string
    {
        if (!is_string($value) || !preg_match('//u', $value) || strlen($value) > $max) {
            throw new InvalidArgumentException($label . ' is invalid or too long.');
        }
        return trim($value);
    }

    private function respond(int $status, string $message, array $data = []): Response
    {
        return Response::make($status, $message)->data(['message' => $message] + $data)
            ->asJson()->header('Cache-Control', 'no-store');
    }
}
