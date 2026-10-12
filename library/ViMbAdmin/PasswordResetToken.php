<?php

/** One-way storage format for password-reset bearer secrets. */
final class ViMbAdmin_PasswordResetToken
{
    private const PREFIX = 'sha256:';

    public static function verifier(string $token): string
    {
        return self::PREFIX . hash('sha256', $token);
    }

    public static function matches(string $stored, string $token): bool
    {
        return self::isVerifier($stored)
            && hash_equals($stored, self::verifier($token));
    }

    public static function isVerifier(string $stored): bool
    {
        return preg_match('/^sha256:[a-f0-9]{64}$/D', $stored) === 1;
    }

    /**
     * @param array<int, mixed>|false $storedTokens
     * @return list<int>
     */
    public static function legacyIndexes(array|false $storedTokens): array
    {
        if ($storedTokens === false) {
            return [];
        }

        $indexes = [];
        foreach ($storedTokens as $storedToken) {
            if (is_array($storedToken)
                && is_int($storedToken['p_index'] ?? null)
                && is_string($storedToken['p_value'] ?? null)
                && !self::isVerifier($storedToken['p_value'])) {
                $indexes[] = $storedToken['p_index'];
            }
        }
        return $indexes;
    }
}
