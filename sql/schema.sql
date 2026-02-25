CREATE TABLE call_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (id),

  contact_id BIGINT UNSIGNED NULL,
  deal_id BIGINT UNSIGNED NULL,
  stage_id VARCHAR(64) NULL,

  bitrix_user_id BIGINT UNSIGNED NULL,
  pbx_user VARCHAR(64) NULL,

  phone VARCHAR(32) NOT NULL,
  score INT NOT NULL DEFAULT 0,

  stage_priority INT NOT NULL DEFAULT 0,
  event_ts DATETIME NOT NULL,

  planned_at DATETIME NULL,
  scheduled_at DATETIME NOT NULL,

  status ENUM(
    'pending',
    'retry',
    'locked',
    'waiting_webhook',
    'done_success',
    'done_skipped_success',
    'done_no_phone',
    'failed',
    'superseded'
  ) NOT NULL DEFAULT 'pending',

  callid VARCHAR(64) NULL,
  dial_started_at DATETIME NULL,
  webhook_deadline_at DATETIME NULL,
  last_webhook_at DATETIME NULL,

  dial_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  postpones TINYINT UNSIGNED NOT NULL DEFAULT 0,

  last_dial_at DATETIME NULL,
  last_error TEXT NULL,

  locked_until DATETIME NULL,
  superseded_by BIGINT UNSIGNED NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_ready (status, scheduled_at),

  INDEX idx_prio_score (stage_priority, score, scheduled_at),

  INDEX idx_manager_tail (pbx_user, status, scheduled_at),

  INDEX idx_contact (contact_id),
  INDEX idx_phone (phone),
  INDEX idx_deal (deal_id),
  INDEX idx_callid (callid),

  INDEX idx_webhook_deadline (status, webhook_deadline_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE call_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (id),

  queue_id BIGINT UNSIGNED NOT NULL,
  attempt_no TINYINT UNSIGNED NOT NULL,

  action VARCHAR(64) NOT NULL,

  ok TINYINT(1) NOT NULL DEFAULT 0,
  info TEXT NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_queue (queue_id),
  CONSTRAINT fk_attempts_queue
    FOREIGN KEY (queue_id) REFERENCES call_queue(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE call_success_cache (
  phone VARCHAR(32) NOT NULL,
  PRIMARY KEY (phone),

  last_success_at DATETIME NOT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'telephony',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE telephony_webhook_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (id),

  received_at DATETIME NOT NULL,
  cmd VARCHAR(32) NOT NULL,
  callid VARCHAR(64) NULL,
  phone VARCHAR(32) NULL,

  payload_json LONGTEXT NOT NULL,

  INDEX idx_cmd (cmd),
  INDEX idx_callid (callid),
  INDEX idx_phone (phone),
  INDEX idx_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
