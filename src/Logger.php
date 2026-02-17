<?php
final class Logger
{
    public static function info(string $msg, array $ctx = []): void
    {
        error_log(
            "[INFO] " .
                $msg .
                " " .
                ($ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : ""),
        );
    }
    public static function error(string $msg, array $ctx = []): void
    {
        error_log(
            "[ERROR] " .
                $msg .
                " " .
                ($ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : ""),
        );
    }
}
