-- Brightery v0.4: asynchronous work, schedule deduplication, audit, personal API credentials, opt-in TOTP.
CREATE TABLE IF NOT EXISTS bf_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 tenant_id BIGINT UNSIGNED NULL,
 handler VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload JSON NOT NULL,
 status ENUM('pending','reserved','completed','failed') NOT NULL DEFAULT 'pending',
 attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
 available_at DATETIME(6) NOT NULL,
 leased_until DATETIME(6) NULL,
 lease_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 last_error VARCHAR(600) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_job_lease(lease_token),
 INDEX idx_job_pick(status,available_at,id),
 INDEX idx_job_tenant(tenant_id,status),
 CONSTRAINT fk_bf_job_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bf_schedule_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 task_name VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 slot_utc VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 job_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_schedule_run(task_name,slot_utc),
 INDEX idx_schedule_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bf_audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 tenant_id BIGINT UNSIGNED NULL,
 actor_id BIGINT UNSIGNED NULL,
 action VARCHAR(100) NOT NULL,
 subject_type VARCHAR(100) NULL,
 subject_id VARCHAR(100) NULL,
 metadata JSON NOT NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX idx_audit_tenant(tenant_id,id),
 INDEX idx_audit_actor(actor_id,id),
 CONSTRAINT fk_audit_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
 CONSTRAINT fk_audit_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bf_api_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 tenant_id BIGINT UNSIGNED NULL,
 name VARCHAR(120) NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scopes JSON NOT NULL,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 last_used_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_api_digest(token_hash),
 INDEX idx_api_user(user_id,revoked_at),
 INDEX idx_api_tenant(tenant_id,revoked_at),
 CONSTRAINT fk_api_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_api_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bf_totp_credentials (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 secret_cipher TEXT NOT NULL,
 enabled_at DATETIME NULL,
 last_counter BIGINT NOT NULL DEFAULT -1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_totp_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
