<?php

namespace Quill\services\rendering;

use event\interfaces\IEvent;
use event\interfaces\IEventListener;
use Quill\services\data\DataConnection;
use URPC\api\events\BeforeRenderEvent;
use URPC\service\BlockIdentity;

/** Adapts the same processor for URPC controllers outside Quill's page controller. */
final class ConnectedDataRenderListener implements IEventListener
{
    public function handle(IEvent $event): array
    {
        if (!$event instanceof BeforeRenderEvent) return [];
        $containsConnection = function (array $blocks, int $depth = 0) use (&$containsConnection): bool {
            if ($depth > 128) throw new \LogicException('Maximum connected block depth exceeded.');
            foreach ($blocks as $block) {
                if (ltrim($block->getBlockDescriptor()->getBlockRenderer(), '\\') === DataConnection::RENDERER) return true;
                if ($containsConnection($block->getBlockChildren(), $depth + 1)) return true;
            }
            return false;
        };
        foreach ($event->getDocument()->getSections() as $blocks) {
            if (!$containsConnection($blocks)) continue;
            $document = (new ConnectedDataRenderer())->prepare($event->getDocument(), $event->getURPC());
            // URPC assigned identities before the event. Reassign expanded siblings using its own API.
            (new BlockIdentity())->assignDocument($document, 'Quill/connected-data');
            $event->setDocument($document);
            break;
        }
        return [];
    }
}
