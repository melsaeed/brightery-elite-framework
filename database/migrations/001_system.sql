-- MySQL 8+; run with php bin/console migrate
CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(150) PRIMARY KEY, executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, name VARCHAR(160) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS groups (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, name VARCHAR(120) NOT NULL UNIQUE,
 description TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS permissions (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, code VARCHAR(120) NOT NULL UNIQUE,
 label VARCHAR(160) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS user_groups (
 user_id BIGINT UNSIGNED NOT NULL, group_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(user_id,group_id),
 CONSTRAINT fk_ug_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_ug_group FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS group_permissions (
 group_id BIGINT UNSIGNED NOT NULL, permission_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(group_id,permission_id),
 CONSTRAINT fk_gp_group FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE,
 CONSTRAINT fk_gp_permission FOREIGN KEY(permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS settings (
 setting_key VARCHAR(150) PRIMARY KEY, setting_value TEXT NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS media (
 id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 user_id BIGINT UNSIGNED NOT NULL, original_name VARCHAR(255) NOT NULL,
 filename CHAR(64) NOT NULL UNIQUE, mime VARCHAR(70) NOT NULL,
 file_size BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_media_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO groups(id,name,description) VALUES (1,'Super Admin','Full administrative access'),(2,'Editor','Content and translations');
INSERT IGNORE INTO permissions(code,label) VALUES ('*','All permissions'),('dashboard.view','Access dashboard'),('modules.manage','Manage modules'),('users.manage','Manage users'),('groups.manage','Manage permissions'),('translations.manage','Manage translations'),('settings.manage','Manage settings'),('media.manage','Manage media');
INSERT IGNORE INTO group_permissions(group_id,permission_id) SELECT 1,id FROM permissions WHERE code='*';
INSERT IGNORE INTO group_permissions(group_id,permission_id) SELECT 2,id FROM permissions WHERE code IN ('dashboard.view','translations.manage','media.manage');
