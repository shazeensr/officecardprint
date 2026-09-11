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

-- Saved cards, shared across every computer/user (previously stored
-- per-browser in IndexedDB, which meant nobody could see cards saved on a
-- different machine). photo_data_url/front_snapshot are base64 data URIs.
CREATE TABLE IF NOT EXISTS saved_cards (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(191) NOT NULL DEFAULT '',
  rc_number VARCHAR(64) NOT NULL DEFAULT '',
  designation VARCHAR(191) NOT NULL DEFAULT '',
  photo_data_url MEDIUMTEXT NULL,
  front_snapshot MEDIUMTEXT NOT NULL,
  created_by VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY saved_cards_rc_number (rc_number),
  KEY saved_cards_full_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
