<?php
declare(strict_types=1);

ini_set("log_errors", "1");
ini_set("error_log", __DIR__ . "/../logs/php_error.log");
error_reporting(E_ALL);

require_once __DIR__ . "/../src/bootstrap.php";
loadDotEnv(__DIR__ . "/../.env");

require_once __DIR__ . "/../src/Config.php";
require_once __DIR__ . "/../src/Db.php";
require_once __DIR__ . "/../src/Phone.php";
require_once __DIR__ . "/../src/Logger.php";
require_once __DIR__ . "/../src/QueueRepo.php";

date_default_timezone_set(Config::env("APP_TIMEZONE", "Asia/Tashkent"));

function readJsonOrPost(): array
{
    $raw = file_get_contents("php://input") ?: "";
    if ($raw !== "") {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return is_array($_POST) ? $_POST : [];
}

function respondJson(int $code, array $body): void
{
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit();
}

function respondOk(array $extra = []): void
{
    respondJson(200, ["ok" => true] + $extra);
}

function respondForbidden(): void
{
    respondJson(403, ["ok" => false, "error" => "forbidden"]);
}

$payload = readJsonOrPost();

$cmd = (string) ($payload["cmd"] ?? "");
$crmToken = (string) ($payload["crm_token"] ?? "");
if (!hash_equals((string) Config::env("TEL_CRM_TOKEN"), $crmToken)) {
    respondForbidden();
}

// нормализация
$callid = (string) ($payload["callid"] ?? "");
$phoneDigits = Phone::digits((string) ($payload["phone"] ?? ""));
$type = strtolower((string) ($payload["type"] ?? "")); // history: in/out
$status = strtolower((string) ($payload["status"] ?? "")); // success/missed/cancel/busy/notavailable/...

// 1) логируем всегда
try {
    $db = new Db();
    $db->tx(function (PDO $pdo) use ($cmd, $callid, $phoneDigits, $payload) {
        $st = $pdo->prepare("
            INSERT INTO telephony_webhook_log(received_at, cmd, callid, phone, payload_json)
            VALUES (NOW(), ?, ?, ?, ?)
        ");
        $st->execute([
            $cmd !== "" ? $cmd : "unknown",
            $callid !== "" ? $callid : null,
            $phoneDigits !== "" ? $phoneDigits : null,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    });
} catch (Throwable $e) {
    Logger::error("telephony webhook log failed", ["e" => $e->getMessage()]);
}

// 2) бизнес-логика — только финальный history
if ($cmd !== "history") {
    respondOk(["ignored" => "cmd_not_history"]);
}

// интересуют только исходящие (out)
// if ($type !== "out") {
//     respondOk(["ignored" => "type_not_out", "type" => $type]);
// }

if ($callid === "" || $phoneDigits === "") {
    respondOk(["ignored" => "missing_callid_or_phone"]);
}

$retryBusySec = Config::int("TEL_RETRY_BUSY_SEC", 600);
$retryNoAnswerSec = Config::int("TEL_RETRY_NOANSWER_SEC", 600); // 10 минут

$db = new Db();
$db->tx(function (PDO $pdo) use (
    $callid,
    $phoneDigits,
    $status,
    $retryBusySec,
    $retryNoAnswerSec,
) {
    $repo = new QueueRepo($pdo);

    $row = $repo->findActiveByCallId($callid);

    // Если не нашли — всё равно полезно обновить cache успеха
    if (!$row) {
        if ($status === "success") {
            $repo->upsertLocalSuccess($phoneDigits, new DateTimeImmutable());
        }
        return;
    }

    $id = (int) $row["id"];
    $attempts = (int) $row["dial_attempts"];
    $pbxUser = (string) ($row["pbx_user"] ?? "");

    $repo->markWebhookReceived($id);

    // SUCCESS
    if ($status === "success") {
        $repo->upsertLocalSuccess($phoneDigits, new DateTimeImmutable());
        $repo->markDone($id, "done_success", "telephony history success");
        $repo->addAttempt($id, $attempts, "history", true, "success");
        return;
    }

    // лимит попыток: attempts уже увеличен в Worker при makeCall
    if ($attempts >= 3) {
        $repo->markDone($id, "failed", "attempts exhausted, status=" . $status);
        $repo->addAttempt($id, $attempts, "history_giveup", true, $status);
        return;
    }

    // BUSY / NOTAVAILABLE => retry через 1 минуту, СЧИТАЕТСЯ попыткой (ты так захотел)
    // => dial_attempts НЕ уменьшаем и НЕ увеличиваем; просто переносим в хвост очереди менеджера.
    if ($status === "busy" || $status === "notavailable") {
        if ($pbxUser !== "") {
            $when = $repo->rescheduleAtManagerTail(
                $id,
                $pbxUser,
                $retryBusySec,
                "retry",
                "status=" . $status,
                true, // postpones++
            );
            $repo->addAttempt(
                $id,
                $attempts,
                "history_retry_busy",
                true,
                "retry_at=" . $when->format("Y-m-d H:i:s"),
            );
        } else {
            // fallback: без хвоста менеджера
            $repo->reschedule(
                $id,
                "retry",
                new DateTimeImmutable("now")->modify(
                    "+{$retryBusySec} seconds",
                ),
                "status=" . $status,
                false,
                true,
            );
            $repo->addAttempt(
                $id,
                $attempts,
                "history_retry_busy",
                true,
                "fallback",
            );
        }
        return;
    }

    // Остальное => “не дозвонились/сброс/прочее” => retry +10 минут в хвост очереди менеджера
    if ($pbxUser !== "") {
        $when = $repo->rescheduleAtManagerTail(
            $id,
            $pbxUser,
            $retryNoAnswerSec,
            "retry",
            "status=" . $status,
            true, // postpones++
        );
        $repo->addAttempt(
            $id,
            $attempts,
            "history_retry",
            true,
            "retry_at=" . $when->format("Y-m-d H:i:s"),
        );
    } else {
        $repo->reschedule(
            $id,
            "retry",
            new DateTimeImmutable("now")->modify(
                "+{$retryNoAnswerSec} seconds",
            ),
            "status=" . $status,
            false,
            true,
        );
        $repo->addAttempt($id, $attempts, "history_retry", true, "fallback");
    }
});

respondOk();
