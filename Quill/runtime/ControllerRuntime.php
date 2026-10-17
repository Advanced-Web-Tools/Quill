<?php

namespace Quill\runtime;

use controller\Controller;
use Exception;
use runtime\api\RuntimeControllerAPI;
use object\ObjectFactory;

final class ControllerRuntime extends RuntimeControllerAPI
{

    private ObjectFactory $factory;


    /**
     * @throws Exception
     */
    public function environmentSetup(): void
    {
        parent::environmentSetup();
        $props = [
            "controllerName" => "QuillPrivateController",
            "name" => "Quill",
            "localAssetPath" => "awt_packages/Quill/views/assets",
            "eventDispatcher" => $this->eventDispatcher,
        ];

        $methods = [
            "setRootPath",
            "setViewPath",
        ];

        $methodArgs = [
            "setRootPath" =>
                [
                    $this->runtimePath,
                ],
            "setViewPath" => [
                "/views/"
            ]
        ];

        $objectFactory = new ObjectFactory();
        $objectFactory->setType(Controller::class);
        $objectFactory->setClassPath($this->runtimePath . "/controllers/QuillController.php");

        $objectFactory->setProperties($props);
        $objectFactory->setMethodCalls($methods);
        $objectFactory->setMethodArgs($methodArgs);


        $this->factory = $objectFactory;
    }

    public function main(): void
    {
        $this->addController($this->factory, "QuillPrivateController");
        $saveFactory = clone $this->factory;
        $saveFactory->setClassPath($this->runtimePath . "/controllers/QuillSaveController.php");
        $saveFactory->setProperties([
            "controllerName" => "QuillSaveController",
            "localAssetPath" => "/awt_packages/Quill/views/assets",
            "eventDispatcher" => $this->eventDispatcher,
        ]);
        $this->addController($saveFactory, "QuillSaveController");
        $publicFactory = clone $this->factory;
        $publicFactory->setClassPath($this->runtimePath . '/controllers/PublicPageController.php');
        $publicFactory->setProperties([
            'controllerName' => 'QuillPublicPageController',
            'localAssetPath' => '/awt_packages/Quill/views/assets',
            'eventDispatcher' => $this->eventDispatcher,
        ]);
        $this->addController($publicFactory, 'QuillPublicPageController');
    }
}
