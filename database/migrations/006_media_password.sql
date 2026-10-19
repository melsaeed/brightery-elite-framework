-- Additive v0.6 tables. Apply once via bin/console migrate on staging first.
CREATE TABLE IF NOT EXISTS bf_media_variants (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 media_id BIGINT UNSIGNED NOT NULL,
 tenant_id BIGINT UNSIGNED NULL,
 storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 mime VARCHAR(80) NOT NULL,
 bytes BIGINT UNSIGNED NOT NULL,
 width SMALLINT UNSIGNED NOT NULL,
 height SMALLINT UNSIGNED NOT NULL,
 fit_mode ENUM('cover','contain') NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_variant_storage(storage_key),
 UNIQUE KEY uq_variant_dimensions(media_id,width,height,fit_mode),
 KEY idx_variant_scope(tenant_id,media_id,id),
 CONSTRAINT fk_variant_media FOREIGN KEY(media_id) REFERENCES bf_private_media(id) ON DELETE CASCADE,
 CONSTRAINT fk_variant_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bf_password_reset_tokens (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 tenant_id BIGINT UNSIGNED NULL,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_password_digest(digest),
 KEY idx_password_expiry(expires_at),
 CONSTRAINT fk_password_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_password_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
