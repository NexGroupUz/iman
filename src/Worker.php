<?php
final class Worker
{
    public function __construct(private Db $db, private TelephonyClient $tel) {}

    private static function utcFmt(DateTimeImmutable $dt): string
    {
        // формат YYYYmmddTHHMMSSZ из совместимой спецификации  [oai_citation:4‡prostiezvonki.ru](https://prostiezvonki.ru/kb/crm-developers-instruction)
        return $dt->setTimezone(new DateTimeZone("UTC"))->format("Ymd\THis\Z");
    }

    private function hasSuccessfulCallFallback(
        TelephonyClient $tel,
        string $phoneDigits,
    ): bool {
        $days = Config::int("TEL_HISTORY_LOOKBACK_DAYS", 180);
        $end = new DateTimeImmutable("now", new DateTimeZone("UTC"));
        $start = $end->sub(new DateInterval("P" . $days . "D"));

        $page = 1;
        $pageSize = 200;

        while (true) {
            $resp = $tel->history(
                self::utcFmt($start),
                self::utcFmt($end),
                $phoneDigits,
                "all",
                $pageSize,
                $page,
            );

            // тут нет жёсткой привязки к точному формату ответа (у разных реализаций отличается)
            $items =
                $resp["data"] ?? ($resp["result"] ?? ($resp["items"] ?? []));
            if (!is_array($items) || count($items) === 0) {
                break;
            }

            foreach ($items as $it) {
                $p = isset($it["phone"])
                    ? Phone::digits((string) $it["phone"])
                    : "";
                $type = strtolower(
                    (string) ($it["type"] ?? ($it["direction"] ?? "")),
                );
                $status = strtolower((string) ($it["status"] ?? ""));
                if (
                    $p === $phoneDigits &&
                    ($type === "out" || $type === "all") &&
                    $status === "success"
                ) {
                    return true;
                }
            }

            // если есть инфо о страницах — используйте её; иначе ограничим 50 страницами
            $page++;
            if ($page > 50) {
                break;
            }
        }

        return false;
    }

    public function tick(): void
    {
        $timeoutSec = Config::int("TEL_WEBHOOK_TIMEOUT_SEC", 30);

        $this->db->tx(function (PDO $pdo) use ($timeoutSec) {
            $repo = new QueueRepo($pdo);
            $repo->releaseExpiredLocks();

            /**
             * A) Таймаут ожидания вебхука:
             * если задача висит в waiting_webhook и scheduled_at <= NOW()
             * значит финальный history не пришёл в пределах SLA -> считаем как “не дозвонились”
             */
            $row = $repo->lockNextWebhookTimeout();
            if ($row) {
                $id = (int) $row["id"];
                $attempts = (int) $row["dial_attempts"];
                $pbxUser = (string) ($row["pbx_user"] ?? "");

                if ($attempts >= 3) {
                    $repo->markDone(
                        $id,
                        "failed",
                        "webhook timeout, attempts exhausted",
                    );
                    $repo->addAttempt(
                        $id,
                        $attempts,
                        "webhook_timeout_giveup",
                        true,
                        "timeout",
                    );
                } else {
                    if ($pbxUser !== "") {
                        $when = $repo->rescheduleAtManagerTail(
                            $id,
                            $pbxUser,
                            60, // 1 минута
                            "retry",
                            "webhook timeout",
                            true, // postpones++
                        );
                        $repo->addAttempt(
                            $id,
                            $attempts,
                            "webhook_timeout_retry",
                            true,
                            "retry_at=" . $when->format("Y-m-d H:i:s"),
                        );
                    } else {
                        // fallback
                        $repo->reschedule(
                            $id,
                            "retry",
                            new DateTimeImmutable("+1 minutes"),
                            "webhook timeout",
                            false,
                            true,
                        );
                        $repo->addAttempt(
                            $id,
                            $attempts,
                            "webhook_timeout_retry",
                            true,
                            "retry +1min",
                        );
                    }
                }
                return;
            }

            /**
             * B) Берём следующую задачу по priority gate
             */
            $row = $repo->lockNextByPriorityGate(); // должен выбирать только pending/retry и scheduled_at<=NOW
            if (!$row) {
                return;
            }

            $id = (int) $row["id"];
            $phone = (string) $row["phone"];
            $pbxUser = (string) ($row["pbx_user"] ?? "");

            if ($pbxUser === "" || $phone === "" || $phone === "0") {
                $repo->markDone($id, "failed", "missing pbx_user or phone");
                $repo->addAttempt(
                    $id,
                    (int) $row["dial_attempts"],
                    "precheck",
                    false,
                    "missing pbx_user/phone",
                );
                return;
            }

            // 1) если уже был успешный звонок для этого телефона — закрываем и не звоним
            $already =
                $repo->hasLocalSuccess($phone) ||
                $this->hasSuccessfulCallFallback($this->tel, $phone);
            if ($already) {
                $repo->markDone(
                    $id,
                    "done_skipped_success",
                    "local success cache",
                );
                $repo->addAttempt(
                    $id,
                    (int) $row["dial_attempts"],
                    "skip_success",
                    true,
                    "local cache",
                );
                return;
            }

            // 2) лимит попыток: dial_attempts считаем попытками дозвона
            $attemptNo = (int) $row["dial_attempts"] + 1;
            if ($attemptNo > 3) {
                $repo->markDone($id, "failed", "attempts exhausted");
                $repo->addAttempt(
                    $id,
                    (int) $row["dial_attempts"],
                    "giveup",
                    true,
                    "attempts exhausted",
                );
                return;
            }

            // 3) инициируем звонок
            try {
                $resp = $this->tel->makeCall($pbxUser, $phone);

                // достаём callid из ответа (варианты на практике отличаются)
                $callid =
                    (string) ($resp["callid"] ?? "") ?:
                    (string) ($resp["data"]["callid"] ?? "") ?:
                    (string) ($resp["result"]["callid"] ?? "");

                $deadline = new DateTimeImmutable("now")->modify(
                    "+{$timeoutSec} seconds",
                );

                if ($callid === "") {
                    throw new RuntimeException(
                        "makeCall returned without callid: " .
                            json_encode($resp, JSON_UNESCAPED_UNICODE),
                    );
                }

                // фиксируем попытку и переводим в ожидание вебхука
                $repo->setDialStarted($id, $attemptNo, $callid, $deadline);

                $repo->addAttempt(
                    $id,
                    $attemptNo,
                    "makecall",
                    true,
                    json_encode($resp, JSON_UNESCAPED_UNICODE),
                );
            } catch (Throwable $e) {
                $msg = $e->getMessage();

                // ошибка makecall: отложим на 1 минуту (считай как временная)
                $repo->reschedule(
                    $id,
                    "retry",
                    new DateTimeImmutable("+1 minute"),
                    $msg,
                    false,
                    true,
                );
                $repo->addAttempt(
                    $id,
                    (int) $row["dial_attempts"],
                    "makecall_error",
                    false,
                    $msg,
                );
            }
        });
    }
}
