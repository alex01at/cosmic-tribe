-- Allianzen, Phase 4. Entscheidung (MVP, bewusst einfach gehalten statt Einladungs-/Antrags-
-- System): JEDE Allianz ist offen beitretbar, solange sie nicht voll ist und der Spieler noch in
-- keiner anderen steckt. Verlassen ist immer sofort möglich. Verlässt der Anführer, übernimmt
-- automatisch das dienstälteste verbleibende Mitglied (nach joined_at) - keine eigene
-- Beförderungs-Aktion nötig. Allianzmitglieder können sich NICHT gegenseitig angreifen/plündern
-- (assertValidMissionTarget in GameEngine.php). Stationierung bei Verbündeten ist BEWUSST NICHT
-- Teil davon - das würde verlangen, dass stationierte fremde Schiffe in CombatResolver mit
-- verteidigen, ein eigener Scope, der hier explizit zurückgestellt wird.

CREATE TABLE alliances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL UNIQUE,
    tag VARCHAR(8) NOT NULL UNIQUE,
    leader_player_id INT UNSIGNED NOT NULL,
    member_cap SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alliances_leader FOREIGN KEY (leader_player_id) REFERENCES players (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE alliance_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alliance_id INT UNSIGNED NOT NULL,
    player_id INT UNSIGNED NOT NULL UNIQUE,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alliance_members_alliance FOREIGN KEY (alliance_id) REFERENCES alliances (id),
    CONSTRAINT fk_alliance_members_player FOREIGN KEY (player_id) REFERENCES players (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_alliance_members_alliance ON alliance_members (alliance_id);
