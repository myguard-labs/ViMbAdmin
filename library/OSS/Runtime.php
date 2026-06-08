<?php

final class OSS_Runtime
{
    private static array $options = [];
    private static string $baseUrl = '';
    private static ?object $entityManager = null;

    public static function configure(array $options, string $baseUrl, object $entityManager): void
    {
        self::$options = $options;
        self::$baseUrl = rtrim($baseUrl, '/');
        self::$entityManager = $entityManager;
    }

    public static function options(): array
    {
        return self::$options;
    }

    public static function option(string $name): mixed
    {
        return self::$options[$name] ?? null;
    }

    public static function baseUrl(): string
    {
        return self::$baseUrl;
    }

    public static function entityManager(): object
    {
        if (self::$entityManager === null) {
            throw new RuntimeException('OSS runtime entity manager is not configured');
        }

        return self::$entityManager;
    }
}
