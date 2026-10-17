<?php
namespace Quill;
use installer\interfaces\package\actions\IPostInstall;
use Quill\services\Migration;

final class install implements IPostInstall
{
    public function postInstall(int $packageId, string $packageName): bool
    {
        (new Migration())->run($packageId);
        return true;
    }
}
