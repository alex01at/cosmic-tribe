-- Allianz-interner Chat - nur für Mitglieder derselben Allianz sichtbar.
CREATE TABLE alliance_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alliance_id INT UNSIGNED NOT NULL,
    player_id INT UNSIGNED NOT NULL,
    body VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alliance_messages_alliance FOREIGN KEY (alliance_id) REFERENCES alliances (id),
    CONSTRAINT fk_alliance_messages_player FOREIGN KEY (player_id) REFERENCES players (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_alliance_messages_alliance ON alliance_messages (alliance_id, id);
