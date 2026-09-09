-- Office Card Print — local users table.
-- LDAP is the source of truth for identity; this table just mirrors
-- LDAP-authenticated users so their app role can be managed locally.
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  name VARCHAR(191) NOT NULL,
  email VARCHAR(191) NULL,
  dn VARCHAR(255) NOT NULL,
  role ENUM('admin','editor','viewer') NOT NULL DEFAULT 'viewer',
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY users_username_unique (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
