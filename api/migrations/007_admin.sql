-- Admin-Verwaltung: eigenes Auth-System (E-Mail+Passwort), komplett getrennt vom
-- Spieler-Auth (device_id+Token) - ein Admin ist kein Spieler, braucht keine Basis.
CREATE TABLE admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Gleiches Muster wie user_tokens (Spieler) - eigene Tabelle statt Spalte auf admins, erlaubt
-- mehrere aktive Sessions (z.B. Desktop + Handy) ohne spaeteres Schema-Brechen.
CREATE TABLE admin_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    FOREIGN KEY (admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Generischer Key-Value-Store fuer Werte, die bisher nur in config.php standen (game_speed) -
-- der Sinn des Admin-Panels ist gerade, dass sowas OHNE Redeploy aenderbar ist. Fehlt ein Key,
-- gilt der Default aus config.php (Bootstrap-Fallback, siehe index.php).
CREATE TABLE game_settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
