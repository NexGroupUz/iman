<?php
require_once __DIR__ . "/../src/Config.php";
require_once __DIR__ . "/../src/Db.php";
require_once __DIR__ . "/../src/Logger.php";
require_once __DIR__ . "/../src/BitrixClient.php";
require_once __DIR__ . "/../src/Phone.php";
require_once __DIR__ . "/../src/QueueRepo.php";
require_once __DIR__ . "/../src/bootstrap.php";
require_once __DIR__ . "./log_bitrix_webhook.php";

loadDotEnv(__DIR__ . "/../.env");

date_default_timezone_set(Config::env("APP_TIMEZONE", "Asia/Tashkent"));

$token = $_POST["auth"]["member_id"] ?? "";
if (!hash_equals(Config::env("APP_WEBHOOK_TOKEN"), (string) $token)) {
    http_response_code(403);
    echo "forbidden";
    exit();
}

$dealId = (int) ($_GET["deal_id"] ?? ($_POST["deal_id"] ?? 0));
$stageId = (string) ($_GET["stage_id"] ?? ($_POST["stage_id"] ?? ""));
$priority = (int) ($_GET["priority"] ?? ($_POST["priority"] ?? 0));

if ($dealId <= 0 || $stageId === "") {
    http_response_code(400);
    echo "bad request";
    exit();
}

$bitrix = new BitrixClient();
$db = new Db();

$pbxMap = Config::json("PBX_MAP_JSON", []);
$scoreField = Config::env("BITRIX_SCORE_FIELD", "UF_CRM_1766473558");

try {
    $deal = $bitrix->getDeal($dealId);
    $contactId = (int) ($deal["CONTACT_ID"] ?? 0);
    $assignedId = (int) ($deal["ASSIGNED_BY_ID"] ?? 0);

    $pbxUser = $pbxMap[(string) $assignedId] ?? "";

    $phoneDigits = "";
    $score = 0;

    if ($contactId > 0) {
        $contact = $bitrix->getContact($contactId);

        // phone can be in PHONE array
        if (!empty($contact["PHONE"]) && is_array($contact["PHONE"])) {
            $raw = (string) ($contact["PHONE"][0]["VALUE"] ?? "");
            $phoneDigits = Phone::digits($raw);
        }

        $score = (int) ($contact[$scoreField] ?? 0);
    }

    if ($phoneDigits === "") {
        // можно либо "failed", либо отдельный статус "done_no_phone"
        $db->tx(function (PDO $pdo) use (
            $dealId,
            $contactId,
            $stageId,
            $assignedId,
            $pbxUser,
            $priority,
        ) {
            $repo = new QueueRepo($pdo);
            $repo->enqueue([
                "contact_id" => $contactId ?: null,
                "deal_id" => $dealId,
                "stage_id" => $stageId,
                "bitrix_user_id" => $assignedId ?: null,
                "pbx_user" => $pbxUser ?: null,
                "phone" => "0",
                "score" => 0,
                "stage_priority" => $priority,
                "event_ts" => new DateTimeImmutable()->format("Y-m-d H:i:s"),
                "scheduled_at" => new DateTimeImmutable()
                    ->modify("+5 minutes")
                    ->format("Y-m-d H:i:s"),
                "status" => "done_no_phone",
            ]);
        });
        echo "ok_no_phone";
        exit();
    }

    $id = $db->tx(function (PDO $pdo) use (
        $dealId,
        $contactId,
        $stageId,
        $assignedId,
        $pbxUser,
        $phoneDigits,
        $score,
        $priority,
    ) {
        $repo = new QueueRepo($pdo);
        return $repo->enqueue([
            "contact_id" => $contactId ?: null,
            "deal_id" => $dealId,
            "stage_id" => $stageId,
            "bitrix_user_id" => $assignedId ?: null,
            "pbx_user" => $pbxUser ?: null,
            "phone" => $phoneDigits,
            "score" => $score,
            "stage_priority" => $priority,
            "event_ts" => new DateTimeImmutable()->format("Y-m-d H:i:s"),
            "scheduled_at" => new DateTimeImmutable()
                ->modify("+5 minutes")
                ->format("Y-m-d H:i:s"),
            "status" => "pending",
        ]);
    });

    echo "ok:" . $id;
} catch (Throwable $e) {
    Logger::error("webhook_bitrix failed", ["e" => $e->getMessage()]);
    http_response_code(500);
    echo "error";
}
