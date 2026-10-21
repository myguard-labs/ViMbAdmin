<?php

declare(strict_types=1);

namespace ViMbAdmin\Tests\BrowserFixture;

final class SuperAdmin
{
    public const USERNAME = 'browser-fixture-admin.invalid';

    public static function requireAdmin(mixed $admin): \Entities\Admin
    {
        if (!$admin instanceof \Entities\Admin
            || $admin->getUsername() !== self::USERNAME || !$admin->isSuper()) {
            throw new \RuntimeException('fixture requires its canonical super admin');
        }
        return $admin;
    }
}
