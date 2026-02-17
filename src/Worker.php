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
        $this->db->tx(function (PDO $pdo) {
            $repo = new QueueRepo($pdo);

            // A) закрываем waiting_result (если используете)
            $row = $repo->lockNextWaitingResult();
            if ($row) {
                $id = (int) $row["id"];
                $phone = (string) $row["phone"];

                // упрощённо: только проверяем успех (локально/по истории) и закрываем
                $ok =
                    $repo->hasLocalSuccess($phone) ||
                    $this->hasSuccessfulCallFallback($this->tel, $phone);

                $repo->addAttempt(
                    $id,
                    (int) $row["dial_attempts"],
                    "history_check",
                    $ok,
                    $ok ? "success found" : "not found",
                );
                if ($ok) {
                    $repo->markDone($id, "done_success");
                } else {
                    // если за разумное время не нашли — считаем неответом и планируем retry +10 мин
                    $attemptNo = (int) $row["dial_attempts"] + 1;
                    if ($attemptNo >= 3) {
                        $repo->markDone(
                            $id,
                            "failed",
                            "no success after attempts",
                        );
                        $repo->addAttempt(
                            $id,
                            $attemptNo,
                            "giveup",
                            true,
                            "attempts exhausted",
                        );
                    } else {
                        $when = new DateTimeImmutable("+10 minutes");
                        $repo->reschedule(
                            $id,
                            "retry",
                            $when,
                            "no answer / dropped",
                            true,
                            true,
                        );
                        $repo->addAttempt(
                            $id,
                            $attemptNo,
                            "postpone_noanswer",
                            true,
                            "retry +10min",
                        );
                    }
                }
                return; // 1 действие за тик — проще и предсказуемее
            }

            // B) берём следующую задачу по priority gate
            $row = $repo->lockNextByPriorityGate();
            if (!$row) {
                return;
            }

            $id = (int) $row["id"];
            $phone = (string) $row["phone"];
            $pbxUser = (string) ($row["pbx_user"] ?? "");

            if ($pbxUser === "") {
                $repo->markDone($id, "failed", "pbx_user not mapped");
                $repo->addAttempt(
                    $id,
                    (int) $row["dial_attempts"],
                    "makecall",
                    false,
                    "missing pbx_user",
                );
                return;
            }

            // 1) уже был успешный звонок? тогда пропускаем
            $already =
                $repo->hasLocalSuccess($phone) ||
                $this->hasSuccessfulCallFallback($this->tel, $phone);
            if ($already) {
                $repo->markDone($id, "done_skipped_success");
                $repo->addAttempt(
                    $id,
                    (int) $row["dial_attempts"],
                    "skip_success",
                    true,
                    "already had successful call",
                );
                return;
            }

            // 2) инициируем звонок
            try {
                $resp = $this->tel->makeCall($pbxUser, $phone);
                $repo->addAttempt(
                    $id,
                    (int) $row["dial_attempts"] + 1,
                    "makecall",
                    true,
                    json_encode($resp, JSON_UNESCAPED_UNICODE),
                );

                // 3) ставим в ожидание результата (через 30 сек проверим историю)
                $repo->reschedule(
                    $id,
                    "waiting_result",
                    new DateTimeImmutable("+30 seconds"),
                    null,
                    true,
                    false,
                );
                $st = $pdo->prepare(
                    "UPDATE call_queue SET last_dial_at=NOW() WHERE id=?",
                );
                $st->execute([$id]);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $lower = strtolower($msg);

                // busy/dnd => +1 мин, не считаем попыткой дозвона
                if (
                    str_contains($lower, "busy") ||
                    str_contains($lower, "dnd")
                ) {
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
                        "postpone_busy",
                        true,
                        $msg,
                    );
                    return;
                }

                // прочие ошибки: +1 мин, но лучше ограничить количеством postpones/attempts
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
                    "makecall",
                    false,
                    $msg,
                );
            }
        });
    }
}
