<?php
declare(strict_types=1);

ini_set("log_errors", "1");
ini_set("error_log", __DIR__ . "/../logs/php_error.log");
error_reporting(E_ALL);

require_once __DIR__ . "/../src/bootstrap.php";
loadDotEnv(__DIR__ . "/../.env");

require_once __DIR__ . "/../src/Config.php";
require_once __DIR__ . "/../src/Db.php";
require_once __DIR__ . "/../src/Logger.php";
require_once __DIR__ . "/../src/BitrixClient.php";
require_once __DIR__ . "/../src/Phone.php";
require_once __DIR__ . "/../src/QueueRepo.php";
require_once __DIR__ . "/log_bitrix_webhook.php";

date_default_timezone_set(Config::env("APP_TIMEZONE", "Asia/Tashkent"));

/**
 * Достаём ID сделки из:
 * - deal_id (если вдруг передали)
 * - document_id[2] = DEAL_599432
 */
function extractDealIdFromBitrix(array $get, array $post): int
{
    if (!empty($get["deal_id"])) {
        return (int) $get["deal_id"];
    }
    if (!empty($post["deal_id"])) {
        return (int) $post["deal_id"];
    }

    $doc = $post["document_id"] ?? null;

    // стандартный кейс Bitrix: ["crm","CCrmDocumentDeal","DEAL_599432"]
    if (is_array($doc) && isset($doc[2])) {
        $v = (string) $doc[2];
        if (preg_match("/DEAL_(\d+)/", $v, $m)) {
            return (int) $m[1];
        }
        if (preg_match("/(\d+)/", $v, $m)) {
            return (int) $m[1];
        }
    }

    // если вдруг строкой
    if (is_string($doc)) {
        if (preg_match("/DEAL_(\d+)/", $doc, $m)) {
            return (int) $m[1];
        }
        if (preg_match("/(\d+)/", $doc, $m)) {
            return (int) $m[1];
        }
    }

    return 0;
}

function extractToken(): string
{
    $t = (string) $_POST["auth"]["member_id"] ?? "";
    if ($t !== "") {
        return $t;
    }
    return "";
}

$token = extractToken();
if (!hash_equals(Config::env("APP_WEBHOOK_TOKEN"), $token)) {
    http_response_code(403);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(
        ["ok" => false, "error" => "forbidden"],
        JSON_UNESCAPED_UNICODE,
    );
    exit();
}

$dealId = extractDealIdFromBitrix($_GET, $_POST);
$priority = (int) ($_GET["priority"] ?? ($_POST["priority"] ?? 0));

if ($dealId <= 0) {
    http_response_code(400);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(
        [
            "ok" => false,
            "error" => "bad request",
            "deal_id" => $dealId,
            "priority" => $priority,
        ],
        JSON_UNESCAPED_UNICODE,
    );
    exit();
}

$bitrix = new BitrixClient();
$db = new Db();

$scoreField = Config::env("BITRIX_SCORE_FIELD", "UF_CRM_1766473558");

try {
    // 1) сделка
    $deal = $bitrix->getDeal($dealId);
    $contactId = (int) ($deal["CONTACT_ID"] ?? 0);
    $assignedId = (int) ($deal["ASSIGNED_BY_ID"] ?? 0);

    $stageId = (string) ($deal["STAGE_ID"] ?? "");
    if ($stageId === "") {
        http_response_code(500);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(
            [
                "ok" => false,
                "error" => "deal_has_no_stage_id",
                "deal_id" => $dealId,
            ],
            JSON_UNESCAPED_UNICODE,
        );
        exit();
    }

    // 2) внутренний номер менеджера из Bitrix (UF_PHONE_INNER)
    $pbxUser = "";
    if ($assignedId > 0) {
        $user = $bitrix->getUser($assignedId);

        $inner = (string) ($user["UF_PHONE_INNER"] ?? "");

        $pbxUser = preg_replace("/\D+/", "", $inner) ?: "";
    }

    if (!in_array($pbxUser, ["1011"])) {
        exit();
    }

    if ($pbxUser === "") {
        // если у менеджера не заполнен внутренний номер — фиксируем ошибку и НЕ ставим звонок
        http_response_code(200);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(
            [
                "ok" => false,
                "error" => "assigned_user_has_no_inner_phone",
                "assigned_by_id" => $assignedId,
                "deal_id" => $dealId,
            ],
            JSON_UNESCAPED_UNICODE,
        );
        exit();
    }

    $phoneDigits = "";
    $score = 0;

    if ($contactId > 0) {
        $contact = $bitrix->getContact($contactId);

        if (!empty($contact["PHONE"]) && is_array($contact["PHONE"])) {
            $raw = (string) ($contact["PHONE"][0]["VALUE"] ?? "");
            $phoneDigits = Phone::digits($raw);
        }

        $score = (int) ($contact[$scoreField] ?? 0);
    }

    // если телефона нет — фиксируем и не ставим в pending
    if ($phoneDigits === "") {
        $queuedId = $db->tx(function (PDO $pdo) use (
            $dealId,
            $contactId,
            $stageId,
            $assignedId,
            $pbxUser,
            $priority,
        ) {
            $repo = new QueueRepo($pdo);
            return $repo->enqueue([
                "contact_id" => $contactId ?: null,
                "deal_id" => $dealId,
                "stage_id" => $stageId,
                "bitrix_user_id" => $assignedId ?: null,
                "pbx_user" => $pbxUser,
                "phone" => "0",
                "score" => 0,
                "stage_priority" => $priority,
                "event_ts" => new DateTimeImmutable()->format("Y-m-d H:i:s"),
                "scheduled_at" => new DateTimeImmutable("+5 minutes")->format(
                    "Y-m-d H:i:s",
                ),
                "status" => "done_no_phone",
            ]);
        });

        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(
            [
                "ok" => true,
                "queued" => $queuedId,
                "status" => "done_no_phone",
                "deal_id" => $dealId,
            ],
            JSON_UNESCAPED_UNICODE,
        );
        exit();
    }

    // 4) enqueue pending
    $queuedId = $db->tx(function (PDO $pdo) use (
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
            "pbx_user" => $pbxUser,
            "phone" => $phoneDigits,
            "score" => $score,
            "stage_priority" => $priority,
            "event_ts" => new DateTimeImmutable()->format("Y-m-d H:i:s"),
            "scheduled_at" => new DateTimeImmutable("+5 minutes")->format(
                "Y-m-d H:i:s",
            ),
            "status" => "pending",
        ]);
    });

    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(
        [
            "ok" => true,
            "queued" => $queuedId,
            "deal_id" => $dealId,
            "stage_id" => $stageId,
            "priority" => $priority,
            "assigned_by_id" => $assignedId,
            "pbx_user" => $pbxUser,
        ],
        JSON_UNESCAPED_UNICODE,
    );
} catch (Throwable $e) {
    Logger::error("webhook_bitrix failed", ["e" => $e->getMessage()]);
    http_response_code(500);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(
        ["ok" => false, "error" => "server_error"],
        JSON_UNESCAPED_UNICODE,
    );
}
