CREATE TABLE IF NOT EXISTS account_login_tokens (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    pending_name VARCHAR(255) NULL,
    return_path VARCHAR(1800) NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_account_login_expiry (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_remember_tokens (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_account_remember_expiry (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_used_magic_links (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    expires_at DATETIME NOT NULL,
    INDEX idx_account_used_magic_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
