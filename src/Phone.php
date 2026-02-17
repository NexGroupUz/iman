<?php
final class Phone
{
    public static function digits(string $raw): string
    {
        $d = preg_replace("/\D+/", "", $raw);
        return $d ?? "";
    }
}
