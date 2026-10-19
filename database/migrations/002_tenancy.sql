-- Control-plane metadata is always global. Tenant application records must include tenant_id.
CREATE TABLE IF NOT EXISTS tenants (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 slug VARCHAR(80) NOT NULL UNIQUE, display_name VARCHAR(160) NOT NULL,
 status ENUM('active','suspended') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tenant_domains (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 tenant_id BIGINT UNSIGNED NOT NULL, hostname VARCHAR(253) NOT NULL UNIQUE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_td_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
 INDEX idx_td_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tenant_memberships (
 tenant_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 role VARCHAR(60) NOT NULL DEFAULT 'member',
 PRIMARY KEY (tenant_id,user_id),
 CONSTRAINT fk_tm_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
 CONSTRAINT fk_tm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_tm_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO permissions(code,label) VALUES ('tenants.manage','Manage tenant companies and domains');
