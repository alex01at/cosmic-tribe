-- Wortfilter gegen rechtlich problematische Inhalte (Allianznamen/-Tags, Chat, Spielernamen) -
-- global über alle Universen hinweg, da das rechtliche Risiko betreiberweit gilt, nicht pro Welt.
CREATE TABLE banned_words (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    word VARCHAR(100) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
