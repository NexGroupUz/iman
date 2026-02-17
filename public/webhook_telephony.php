<?php
require_once __DIR__ . "/../src/Config.php";
require_once __DIR__ . "/../src/Db.php";
require_once __DIR__ . "/../src/Phone.php";
require_once __DIR__ . "/../src/QueueRepo.php";
require_once __DIR__ . "/../src/bootstrap.php";
loadDotEnv(__DIR__ . "/../.env");

$payload = json_decode(file_get_contents("php://input"), true);
if (!is_array($payload)) {
    echo "ok";
    exit();
}

if (($payload["cmd"] ?? "") !== "history") {
    echo "ok";
    exit();
}

$phone = Phone::digits((string) ($payload["phone"] ?? ""));
$status = strtolower((string) ($payload["status"] ?? ""));
$type = strtolower((string) ($payload["type"] ?? ""));

if ($phone === "" || $type !== "out") {
    echo "ok";
    exit();
}

$db = new Db();
$db->tx(function (PDO $pdo) use ($phone, $status) {
    $repo = new QueueRepo($pdo);
    if ($status === "success") {
        $repo->upsertLocalSuccess($phone, new DateTimeImmutable());
    }
});

echo "ok";
