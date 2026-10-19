<?php

namespace Quill\controllers;

use admin\Admin;
use Dashboard\api\page\DashboardControllerBase;
use response\Response;

/** Quill owns access checks; Dashboard supplies only the navigation and page shell. */
abstract class QuillControllerBase extends DashboardControllerBase
{
    public const PERMISSION_LEVEL = 3;

    final protected function checkAuth(): void
    {
        $admin = new Admin();
        if (!$admin->checkAuthentication()) {
            $this->denyAccess(401, 'Your session expired. Sign in again to use Quill.');
        }
        if (!$admin->checkPermission(self::PERMISSION_LEVEL)) {
            $this->denyAccess(403, 'You do not have permission to use Quill.');
        }
    }

    private function denyAccess(int $status, string $message): never
    {
        Response::make($status, $message)->data([])->asJson()
            ->header('Cache-Control', 'private, no-store')->send();
        exit;
    }
}
