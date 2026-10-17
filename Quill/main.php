<?php

use runtime\api\RuntimeLinkerAPI;
use Dashboard\api\events\NavigationResponseEvent;
use Dashboard\api\navigation\NavigationItem;

final class Quill extends RuntimeLinkerAPI
{
    public function environmentSetup(): void
    {
        parent::environmentSetup();
        $this->waitForPackage('Dashboard');
    }

    public function main(): void
    {
        $this->createLink('controllers', 'runtime/ControllerRuntime.php');
        $this->createLink('routes', 'runtime/RouterRuntime.php');
        $this->eventDispatcher->addListener(\URPC\api\events\BeforeRenderEvent::NAME, new \Quill\services\rendering\ConnectedDataRenderListener());

        $nav = new NavigationItem('Quill', '/dashboard/quill', $this->getInfo()['icon'], [
            new NavigationItem('Pages', '/dashboard/quill', null, [], 1),
            new NavigationItem('Components', '/dashboard/quill/components', null, [], 1),
            new NavigationItem('Routes', '/dashboard/quill/routes', null, [], 1),
        ], 1);

        $this->eventDispatcher->addListener(NavigationResponseEvent::NAME, new NavigationResponseEvent($nav));
    }
}
