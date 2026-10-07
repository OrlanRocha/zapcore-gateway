ALTER TABLE message_media
    MODIFY COLUMN file_size BIGINT NULL,
    ADD COLUMN IF NOT EXISTS storage_origin ENUM('incoming', 'outgoing') NOT NULL DEFAULT 'incoming' AFTER file_size,
    ADD COLUMN IF NOT EXISTS removed_at TIMESTAMP NULL AFTER storage_origin,
    ADD COLUMN IF NOT EXISTS removal_reason VARCHAR(100) NULL AFTER removed_at;

CREATE INDEX IF NOT EXISTS idx_message_media_created ON message_media (created_at, id);
CREATE INDEX IF NOT EXISTS idx_message_media_removed ON message_media (removed_at, created_at);

CREATE TABLE IF NOT EXISTS storage_settings (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    mode ENUM('percent', 'absolute') NOT NULL DEFAULT 'percent',
    percent_threshold TINYINT UNSIGNED NULL DEFAULT 80,
    percent_target TINYINT UNSIGNED NULL DEFAULT 75,
    absolute_bytes BIGINT UNSIGNED NULL,
    interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    last_run_at TIMESTAMP NULL,
    last_run_status ENUM('completed', 'partial', 'failed', 'skipped') NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_storage_settings_singleton CHECK (id = 1),
    CONSTRAINT chk_storage_percentages CHECK (
        mode <> 'percent' OR (
            percent_threshold BETWEEN 1 AND 99
            AND percent_target BETWEEN 0 AND 98
            AND percent_target < percent_threshold
        )
    ),
    CONSTRAINT chk_storage_absolute CHECK (mode <> 'absolute' OR absolute_bytes > 0),
    CONSTRAINT chk_storage_interval CHECK (interval_minutes IN (5, 15, 30, 60, 360, 1440)),
    FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO storage_settings (id, enabled, mode, percent_threshold, percent_target, interval_minutes)
VALUES (1, FALSE, 'percent', 80, 75, 60)
ON DUPLICATE KEY UPDATE id = VALUES(id);

CREATE TABLE IF NOT EXISTS storage_cleanup_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    triggered_by ENUM('cron', 'manual', 'dry_run') NOT NULL,
    mode ENUM('percent', 'absolute') NOT NULL,
    threshold_value BIGINT NOT NULL,
    target_value BIGINT NULL,
    bytes_before BIGINT NOT NULL DEFAULT 0,
    bytes_after BIGINT NOT NULL DEFAULT 0,
    files_before BIGINT NOT NULL DEFAULT 0,
    files_after BIGINT NOT NULL DEFAULT 0,
    bytes_selected BIGINT NOT NULL DEFAULT 0,
    files_selected BIGINT NOT NULL DEFAULT 0,
    bytes_deleted BIGINT NOT NULL DEFAULT 0,
    files_deleted BIGINT NOT NULL DEFAULT 0,
    status ENUM('completed', 'partial', 'failed', 'skipped') NOT NULL,
    error_summary TEXT NULL,
    user_id INT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    KEY idx_storage_cleanup_runs_started (started_at, id),
    KEY idx_storage_cleanup_runs_status (status, started_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
