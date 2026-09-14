<?php

declare(strict_types=1);

final class ViMbAdminPlugin_WorkflowProbe implements OSS_Plugin_Observer
{
    /** @var list<string> */
    public static array $events = [];
    public function update(string $controller, string $action, string $hook, object $context, ?array $params = null): bool
    {
        self::$events[] = $controller . '_' . $action . '_' . $hook;
        return true;
    }
}
