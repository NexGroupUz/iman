<?php
final class QueueRepo
{
    public function __construct(private PDO $pdo) {}

    public function addAttempt(
        int $queueId,
        int $attemptNo,
        string $action,
        bool $ok,
        ?string $info = null,
    ): void {
        $st = $this->pdo->prepare(
            "INSERT INTO call_attempts(queue_id, attempt_no, action, ok, info) VALUES(?,?,?,?,?)",
        );
        $st->execute([$queueId, $attemptNo, $action, $ok ? 1 : 0, $info]);
    }

    /**
     * Insert new queue item, supersede older active items for same contact/phone.
     */
    public function enqueue(array $row): int
    {
        $st = $this->pdo->prepare("
          INSERT INTO call_queue(
            contact_id, deal_id, stage_id, bitrix_user_id, pbx_user,
            phone, score, stage_priority, event_ts, planned_at, scheduled_at, status
          ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");

        $st->execute([
            $row["contact_id"],
            $row["deal_id"],
            $row["stage_id"],
            $row["bitrix_user_id"],
            $row["pbx_user"],
            $row["phone"],
            $row["score"],
            $row["stage_priority"],
            $row["event_ts"],
            $row["scheduled_at"], // planned_at = первое расписание (+5 минут)
            $row["scheduled_at"], // scheduled_at = когда реально исполнить
            $row["status"],
        ]);

        $newId = (int) $this->pdo->lastInsertId();

        // Supersede older items for same contact (or phone if contact unknown)
        if (!empty($row["contact_id"])) {
            $st2 = $this->pdo->prepare("
        UPDATE call_queue
        SET status='superseded', superseded_by=?, scheduled_at=scheduled_at
        WHERE id <> ?
          AND contact_id = ?
          AND status IN ('pending','retry','locked','waiting_webhook')
      ");
            $st2->execute([$newId, $newId, $row["contact_id"]]);
        } else {
            $st2 = $this->pdo->prepare("
        UPDATE call_queue
        SET status='superseded', superseded_by=?
        WHERE id <> ?
          AND phone = ?
          AND status IN ('pending','retry','locked','waiting_webhook')
      ");
            $st2->execute([$newId, $newId, $row["phone"]]);
        }

        $this->addAttempt(
            $newId,
            0,
            "supersede",
            true,
            "enqueue superseded older items",
        );
        return $newId;
    }

    public function markDone(int $id, string $status, ?string $err = null): void
    {
        $st = $this->pdo->prepare(
            "UPDATE call_queue SET status=?, last_error=?, locked_until=NULL WHERE id=?",
        );
        $st->execute([$status, $err, $id]);
    }

    public function reschedule(
        int $id,
        string $status,
        DateTimeImmutable $when,
        ?string $err = null,
        bool $incDialAttempts = false,
        bool $incPostpones = false,
    ): void {
        $sql = "UPDATE call_queue
            SET status=?, scheduled_at=?, last_error=?, locked_until=NULL";
        if ($incDialAttempts) {
            $sql .= ", dial_attempts = dial_attempts + 1";
        }
        if ($incPostpones) {
            $sql .= ", postpones = postpones + 1";
        }
        $sql .= " WHERE id=?";

        $st = $this->pdo->prepare($sql);
        $st->execute([$status, $when->format("Y-m-d H:i:s"), $err, $id]);
    }

    public function lockNextByPriorityGate(): ?array
    {
        // 1) определяем максимальный приоритет среди готовых pending/retry
        $st = $this->pdo->query("
      SELECT MAX(stage_priority) AS p
      FROM call_queue
      WHERE status IN ('pending','retry')
        AND scheduled_at <= NOW()
    ");
        $p = $st->fetchColumn();
        if ($p === null) {
            return null;
        }

        // 2) выбираем задачу внутри этого приоритета по Score DESC
        $st2 = $this->pdo->prepare("
      SELECT *
      FROM call_queue
      WHERE status IN ('pending','retry')
        AND scheduled_at <= NOW()
        AND stage_priority = ?
      ORDER BY score DESC, scheduled_at ASC, id ASC
      LIMIT 1
      FOR UPDATE
    ");
        $st2->execute([(int) $p]);
        $row = $st2->fetch();
        if (!$row) {
            return null;
        }

        $st3 = $this->pdo->prepare(
            "UPDATE call_queue SET status='locked', locked_until=DATE_ADD(NOW(), INTERVAL 30 SECOND) WHERE id=?",
        );
        $st3->execute([(int) $row["id"]]);
        return $row;
    }

    public function hasLocalSuccess(string $phone): bool
    {
        $st = $this->pdo->prepare(
            "SELECT 1 FROM call_success_cache WHERE phone=? LIMIT 1",
        );
        $st->execute([$phone]);
        return (bool) $st->fetchColumn();
    }

    public function upsertLocalSuccess(
        string $phone,
        DateTimeImmutable $dt,
    ): void {
        $st = $this->pdo->prepare("
      INSERT INTO call_success_cache(phone, last_success_at) VALUES(?,?)
      ON DUPLICATE KEY UPDATE last_success_at=VALUES(last_success_at)
    ");
        $st->execute([$phone, $dt->format("Y-m-d H:i:s")]);
    }

    public function findActiveByCallId(string $callid): ?array
    {
        $st = $this->pdo->prepare("
            SELECT * FROM call_queue
            WHERE callid = ?
              AND status IN ('waiting_webhook','pending','retry','locked')
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$callid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function decrementDialAttemptsSafe(int $id): void
    {
        $st = $this->pdo->prepare("
            UPDATE call_queue
               SET dial_attempts = IF(dial_attempts>0, dial_attempts-1, 0),
                   updated_at=NOW()
             WHERE id=?
        ");
        $st->execute([$id]);
    }

    /**
     * Должен лочить одну задачу, у которой истёк дедлайн вебхука
     */
    public function lockNextWebhookTimeout(): ?array
    {
        $st = $this->pdo->prepare("
             SELECT * FROM call_queue
             WHERE status='waiting_webhook'
               AND webhook_deadline_at IS NOT NULL
               AND webhook_deadline_at <= NOW()
             ORDER BY webhook_deadline_at ASC
             LIMIT 1
             FOR UPDATE
         ");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
    public function setDialStarted(
        int $id,
        int $attemptNo,
        string $callid,
        DateTimeImmutable $webhookDeadline,
    ): void {
        $st = $this->pdo->prepare("
            UPDATE call_queue
               SET dial_attempts=?,
                   callid=?,
                   dial_started_at=NOW(),
                   webhook_deadline_at=?,
                   scheduled_at=?,      -- next run = дедлайн вебхука
                   last_dial_at=NOW(),
                   status='waiting_webhook',
                   locked_until=NULL
             WHERE id=?
        ");
        $deadlineStr = $webhookDeadline->format("Y-m-d H:i:s");
        $st->execute([
            $attemptNo,
            $callid !== "" ? $callid : null,
            $deadlineStr,
            $deadlineStr,
            $id,
        ]);
    }
    public function markWebhookReceived(int $id): void
    {
        $st = $this->pdo->prepare(
            "UPDATE call_queue SET last_webhook_at=NOW() WHERE id=?",
        );
        $st->execute([$id]);
    }

    public function releaseExpiredLocks(): int
    {
        $st = $this->pdo->prepare("
            UPDATE call_queue
               SET status='retry', locked_until=NULL
             WHERE status='locked'
               AND locked_until IS NOT NULL
               AND locked_until < NOW()
        ");
        $st->execute();
        return $st->rowCount();
    }

    /**
     * Вернуть время "после хвоста очереди менеджера", с gap (например 10 минут).
     * active statuses: pending, retry, waiting_webhook, locked
     */
    public function calcTailAfter(
        string $pbxUser,
        DateTimeImmutable $base,
        int $gapSeconds,
        int $excludeId,
    ): DateTimeImmutable {
        $st = $this->pdo->prepare("
            SELECT MAX(scheduled_at) AS mx
              FROM call_queue
             WHERE pbx_user = ?
               AND id <> ?
               AND status IN ('pending','retry','locked')
        ");
        $st->execute([$pbxUser, $excludeId]);
        $mx = $st->fetchColumn();

        $tail = null;
        if (is_string($mx) && $mx !== "") {
            $tail = new DateTimeImmutable($mx);
        }

        $cand1 = $base;
        if ($tail) {
            $cand2 = $tail->modify("+{$gapSeconds} seconds");
            return $cand2 > $cand1 ? $cand2 : $cand1;
        }
        return $cand1;
    }

    /**
     * Retry в конец очереди менеджера: retry_at = max(now+delay, tail+delay)
     */
    public function rescheduleAtManagerTail(
        int $id,
        string $pbxUser,
        int $delaySeconds,
        string $status,
        ?string $err,
        bool $incPostpones,
    ): DateTimeImmutable {
        $base = new DateTimeImmutable("now")->modify(
            "+{$delaySeconds} seconds",
        );
        $when = $this->calcTailAfter($pbxUser, $base, $delaySeconds, $id);

        $this->reschedule(
            $id,
            $status,
            $when,
            $err,
            false, // dial_attempts НЕ трогаем здесь
            $incPostpones,
        );

        return $when;
    }
}
