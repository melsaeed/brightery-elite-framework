-- Brightery Framework v0.7.0: transactional usage counters, quota overrides.
-- The central control plane is scope_id 0; positive IDs are tenant primary keys.
-- Never update these counters except through MediaQuota's transaction-bound reservations/releases.
CREATE TABLE IF NOT EXISTS bf_media_usage (
 scope_id BIGINT UNSIGNED PRIMARY KEY,
 used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
 original_count INT UNSIGNED NOT NULL DEFAULT 0,
 variant_count INT UNSIGNED NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bf_media_quotas (
 scope_id BIGINT UNSIGNED PRIMARY KEY,
 max_bytes BIGINT UNSIGNED NOT NULL,
 max_files INT UNSIGNED NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Backfill includes existing 0.5 and 0.6 media and is idempotent across a partial DDL migration retry.
INSERT INTO bf_media_usage(scope_id,used_bytes,original_count,variant_count)
 SELECT COALESCE(tenant_id,0),COALESCE(SUM(bytes),0),COUNT(*),0
 FROM bf_private_media GROUP BY COALESCE(tenant_id,0)
 ON DUPLICATE KEY UPDATE used_bytes=VALUES(used_bytes),original_count=VALUES(original_count),variant_count=0;
UPDATE bf_media_usage u JOIN (
 SELECT COALESCE(tenant_id,0) scope_id, SUM(bytes) variant_bytes, COUNT(*) variants
 FROM bf_media_variants GROUP BY COALESCE(tenant_id,0)
) v ON v.scope_id=u.scope_id
SET u.used_bytes=u.used_bytes+v.variant_bytes,u.variant_count=v.variants;
