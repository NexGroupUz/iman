<?php
declare(strict_types=1);

/**
 * public/test.php
 * Логирует входящие запросы Bitrix в файл.
 * rid = дата/время.
 */

function getAllHeadersSafe(): array
{
    if (function_exists("getallheaders")) {
        return getallheaders() ?: [];
    }
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, "HTTP_")) {
            $name = str_replace("_", "-", strtolower(substr($k, 5)));
            $headers[$name] = $v;
        }
    }
    return $headers;
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function rotateIfTooBig(string $file, int $maxBytes = 5_000_000): void
{
    if (is_file($file) && filesize($file) > $maxBytes) {
        $ts = date("Ymd_His");
        @rename($file, $file . "." . $ts);
    }
}

$method = $_SERVER["REQUEST_METHOD"] ?? "UNKNOWN";
$uri = $_SERVER["REQUEST_URI"] ?? "";
$remote = $_SERVER["REMOTE_ADDR"] ?? "";
$ct = $_SERVER["CONTENT_TYPE"] ?? "";
$raw = file_get_contents("php://input") ?: "";
$headers = getAllHeadersSafe();

// rid = дата/время + микросекунды (чтобы не слипалось при частых запросах)
$rid =
    date("Y-m-d_H:i:s") .
    "_" .
    sprintf(
        "%06d",
        (int) ((microtime(true) - floor(microtime(true))) * 1_000_000),
    );

$entry = [
    "rid" => $rid,
    "time" => date("Y-m-d H:i:s"),
    "remote" => $remote,
    "method" => $method,
    "uri" => $uri,
    "contentType" => $ct,
    "headers" => $headers,
    "get" => $_GET,
    "post" => $_POST,
    "raw" => $raw,
];

// JSON decode (если похоже на JSON)
$rawTrim = ltrim($raw);
if ($rawTrim !== "" && ($rawTrim[0] === "{" || $rawTrim[0] === "[")) {
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $entry["json"] = $json;
    } else {
        $entry["json_error"] = json_last_error_msg();
    }
}

// form-urlencoded fallback
if (
    empty($_POST) &&
    $raw !== "" &&
    str_contains(strtolower($ct), "application/x-www-form-urlencoded")
) {
    parse_str($raw, $parsed);
    if (!empty($parsed)) {
        $entry["parsed_form"] = $parsed;
    }
}

// куда писать лог
$root = realpath(__DIR__ . "/..") ?: dirname(__DIR__);
$logDir = $root . "/logs";
ensureDir($logDir);

$logFile = $logDir . "/bitrix_webhook.log";
rotateIfTooBig($logFile);

$line =
    "----- {$rid} -----\n" .
    json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) .
    "\n\n";
file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

// ответ Bitrix
header("Content-Type: application/json; charset=utf-8");
echo json_encode(["ok" => true, "rid" => $rid], JSON_UNESCAPED_UNICODE);
