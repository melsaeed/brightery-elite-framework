-- v0.9.0: central operations heartbeat; no tenant data or credentials in this table.
CREATE TABLE IF NOT EXISTS bf_worker_heartbeats (
 worker_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 state ENUM('idle','working','stopped') NOT NULL DEFAULT 'idle',
 jobs_processed BIGINT UNSIGNED NOT NULL DEFAULT 0,
 started_at DATETIME(6) NOT NULL,
 last_seen_at DATETIME(6) NOT NULL,
 INDEX idx_worker_activity(last_seen_at,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
