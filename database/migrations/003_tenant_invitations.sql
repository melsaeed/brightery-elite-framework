-- Global account identities, tenant-scoped memberships, one-time onboarding grants.
-- Sensitive token plaintext is never persisted.
CREATE TABLE IF NOT EXISTS tenant_invitations (
 id BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
 tenant_id BIGINT UNSIGNED NOT NULL,
 email VARCHAR(190) NOT NULL,
 role VARCHAR(60) NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL DEFAULT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_invitation_digest(token_hash),
 KEY idx_invitation_tenant_email(tenant_id,email),
 KEY idx_invitation_expiry(expires_at),
 CONSTRAINT fk_invite_tenant FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
 CONSTRAINT fk_invite_actor FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
