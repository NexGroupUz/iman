CREATE TABLE call_queue (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

  contact_id BIGINT UNSIGNED NULL,
  deal_id BIGINT UNSIGNED NULL,
  stage_id VARCHAR(64) NULL,

  bitrix_user_id BIGINT UNSIGNED NULL,     -- ASSIGNED_BY_ID
  pbx_user VARCHAR(64) NULL,               -- extension/login for makecall

  phone VARCHAR(32) NOT NULL,              -- digits only
  score INT NOT NULL DEFAULT 0,

  stage_priority INT NOT NULL DEFAULT 0,   -- priority from webhook
  event_ts DATETIME NOT NULL,              -- when stage event arrived
  scheduled_at DATETIME NOT NULL,          -- when to attempt next action

  status ENUM(
    'pending',
    'retry',
    'locked',
    'waiting_result',
    'done_success',
    'done_skipped_success',
    'done_no_phone',
    'failed',
    'superseded'
  ) NOT NULL DEFAULT 'pending',

  dial_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,    -- counts real dial attempts
  postpones TINYINT UNSIGNED NOT NULL DEFAULT 0,

  last_dial_at DATETIME NULL,
  last_error TEXT NULL,

  locked_until DATETIME NULL,
  superseded_by BIGINT UNSIGNED NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_ready (status, scheduled_at),
  INDEX idx_prio_score (stage_priority, score, scheduled_at),
  INDEX idx_contact (contact_id),
  INDEX idx_phone (phone),
  INDEX idx_deal (deal_id)
) ENGINE=InnoDB;

CREATE TABLE call_attempts (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  queue_id BIGINT UNSIGNED NOT NULL,
  attempt_no TINYINT UNSIGNED NOT NULL,
  action ENUM('makecall','history_check','skip_success','postpone_busy','postpone_noanswer','giveup','supersede') NOT NULL,
  ok TINYINT(1) NOT NULL DEFAULT 0,
  info TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_queue (queue_id),
  CONSTRAINT fk_attempts_queue FOREIGN KEY (queue_id) REFERENCES call_queue(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- опционально: кэш успешных звонков, если подключите webhook телефонии
CREATE TABLE call_success_cache (
  phone VARCHAR(32) PRIMARY KEY,
  last_success_at DATETIME NOT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'telephony',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
