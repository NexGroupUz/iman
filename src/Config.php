<?php
final class Config
{
    public static function env(string $key, ?string $default = null): string
    {
        $v = getenv($key);
        if ($v === false || $v === "") {
            if ($default !== null) {
                return $default;
            }
            throw new RuntimeException("Missing env: {$key}");
        }
        return $v;
    }

    public static function int(string $key, int $default): int
    {
        $v = getenv($key);
        if ($v === false || $v === "") {
            return $default;
        }
        return (int) $v;
    }

    public static function json(string $key, array $default = []): array
    {
        $v = getenv($key);
        if ($v === false || $v === "") {
            return $default;
        }
        $d = json_decode($v, true);
        if (!is_array($d)) {
            throw new RuntimeException("Invalid JSON env: {$key}");
        }
        return $d;
    }
}
