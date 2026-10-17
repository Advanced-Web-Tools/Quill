<?php
namespace Quill;

use Quill\services\Migration;

final class update implements \installer\interfaces\package\actions\IPostUpdate
{
    public function postUpdate(int $packageId, string $packageName): void
    {
        (new Migration())->run($packageId);
    }
}
