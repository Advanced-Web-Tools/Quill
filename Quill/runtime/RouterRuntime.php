<?php

namespace Quill\runtime;

use runtime\api\RuntimeRouterAPI;
use router\Router;
use Quill\services\routes\RouteRegistration;

final class RouterRuntime extends RuntimeRouterAPI
{
    public function main(): void
    {
        $runtime = $this->getPassable(ControllerRuntime::class) ?? throw new \LogicException('Quill controller runtime missing');
        $controller = $runtime->getController('QuillPrivateController');
        $saveController = $runtime->getController('QuillSaveController');
        $this->addRouter((new Router('/dashboard/quill/external-component/{key}', 'externalEditorComponent', clone $saveController))->setMethod('GET'));
        $this->addRouter((new Router('/dashboard/quill/import-external-page', 'importExternalPage', clone $saveController))->setMethod('POST'));
        $this->addRouter((new Router('/quill/preview/external/{key}', 'externalPreview', clone $controller))->setMethod('GET'));
        $this->addRouter((new Router('/dashboard/quill/document/{type}/{id}', 'saveDocument', clone $saveController))->setMethod('POST'));
        $this->addRouter((new Router('/dashboard/quill/editor-component', 'createEditorComponent', clone $saveController))->setMethod('POST'));
        $this->addRouter((new Router('/dashboard/quill/editor-component/{id}', 'editorComponent', clone $saveController))->setMethod('GET'));
        $this->addRouter((new Router('/dashboard/quill/editor-component/{id}/block', 'detachedEditorComponent', clone $saveController))->setMethod('GET'));
        $this->addRouter((new Router('/dashboard/quill/editor-animation', 'editorAnimations', clone $saveController))->setMethod('GET'));
        $this->addRouter((new Router('/dashboard/quill/editor-animation/{id}', 'editorAnimations', clone $saveController))->setMethod('GET'));
        $this->addRouter((new Router('/dashboard/quill/editor-animation', 'saveEditorAnimation', clone $saveController))->setMethod('POST'));
        $this->addRouter((new Router('/dashboard/quill/editor-animation/{id}', 'saveEditorAnimation', clone $saveController))->setMethod('POST'));
        $this->addRouter((new Router('/dashboard/quill/editor-animation/{id}/delete', 'deleteEditorAnimation', clone $saveController))->setMethod('POST'));
        $this->addRouter((new Router('/dashboard/quill/editor-color-palette', 'editorPalettes', clone $saveController))->setMethod('GET'));
        $this->addRouter((new Router('/dashboard/quill/editor-color-palette/{id}', 'editorPalettes', clone $saveController))->setMethod('GET'));
        $this->addRouter((new Router('/dashboard/quill/editor-color-palette', 'saveEditorPalette', clone $saveController))->setMethod('POST'));
        $this->addRouter((new Router('/dashboard/quill/editor-color-palette/{id}', 'saveEditorPalette', clone $saveController))->setMethod('POST'));
        $this->addRouter((new Router('/dashboard/quill/editor-color-palette/{id}/delete', 'deleteEditorPalette', clone $saveController))->setMethod('POST'));
        foreach (['page', 'component', 'route'] as $type) {
            $this->addRouter(
                (new Router('/dashboard/quill/create/' . $type, $type, clone $saveController))
                    ->setMethod('POST')
            );
            foreach (['update', 'delete'] as $operation) {
                $this->addRouter(
                    (new Router('/dashboard/quill/' . $operation . '/' . $type, $operation . ucfirst($type), clone $saveController))
                        ->setMethod('POST')
                );
            }
        }

        foreach (['templates','history','preview'] as $action) {
            $path=match($action){'templates'=>'/dashboard/quill/templates','history'=>'/dashboard/quill/page/{id}/history','preview'=>'/quill/preview/page/{id}'};
            $this->addRouter((new Router($path,$action,clone $controller))->setMethod('GET'));
        }
        foreach (['update','delete'] as $operation) $this->addRouter((new Router('/dashboard/quill/'.$operation.'/template',$operation.'Template',clone $saveController))->setMethod('POST'));
        foreach (['template','restore','delete-snapshot'] as $operation) {
            $path=$operation==='template'?'/dashboard/quill/page/{id}/template':'/dashboard/quill/page/{id}/snapshot/{snapshotId}/'.$operation;
            $route=(new Router($path,match($operation){'template'=>'savePageTemplate','restore'=>'restorePageSnapshot',default=>'deletePageSnapshot'},clone $saveController))->setMethod('POST');
            $this->addRouter($route);
        }

        $this->addRouter(
            (new Router(
                "/dashboard/quill",
                "index",
                $controller
            ))
        );

        $this->addRouter(
            (new Router(
                "/dashboard/quill/routes",
                "routes",
                $controller
            ))
        );

        $this->addRouter(
            (new Router(
                "/dashboard/quill/components",
                "components",
                $controller
            ))
        );

        $this->addRouter(
            (new Router(
                "/dashboard/quill/editor",
                "editor",
                $controller
            ))
        );
        $this->addRouter(
            (new Router('/quill/editor/{type}/{id}', 'editor', $controller))->setMethod('GET')
        );
        foreach ((new RouteRegistration())->getRoutes($runtime->getController('QuillPublicPageController')) as $route) {
            $this->addRouter($route);
        }
    }
}
