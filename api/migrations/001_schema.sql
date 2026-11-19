-- Starforge (Arbeitstitel) — vollständiges, normalisiertes Grundschema für Phase 1.
-- Lords & Knights-artiges, asynchrones Multiplayer-Aufbau-/Strategiespiel im Weltraum,
-- eigenständiges Sci-Fi-Universum ohne geschützte Markenbegriffe.
--
-- Grundsätze (gelten für jede künftige Migration in diesem Projekt):
--   - Server-authoritative: der Client sendet nur Absichten, der Server berechnet alles.
--   - Keine PHP-Sessions — stateless Bearer-Token-Auth über user_tokens (siehe unten).
--   - Keine Dauerprozesse für Produktion/Bauzeiten/Flugzeiten — alles wird aus
--     Unix-/DATETIME-Zeitstempeln lazy bei jedem Request nachgerechnet (started_at/finishes_at/
--     last_tick_at). Ein optionaler Cronjob kommt erst in Phase 2 für Kampfauflösungen dazu,
--     falls lazy Evaluation dafür nicht reicht (Spieler könnte sonst durch Nicht-Einloggen eine
--     fällige Niederlage ewig hinauszögern).
--   - Neue Schemaänderungen immer als neue 00N_*.sql-Datei, nie diese Datei nachträglich editieren
--     (siehe bin/migrate.php, überspringt bereits angewandte Dateien).

CREATE TABLE players (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL UNIQUE,
    username VARCHAR(40) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Getrennt von players (statt token_hash direkt auf players wie im Vorgängerprojekt) - erlaubt
-- mehrere aktive Tokens pro Spieler (mehrere Geräte) von Anfang an, ohne das Schema später
-- brechen zu müssen.
CREATE TABLE user_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    device_label VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    FOREIGN KEY (player_id) REFERENCES players(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_user_tokens_player ON user_tokens (player_id);

-- Galaxie-Raster: 2D-Koordinaten, in Sektoren unterteilt. EIN Sektor ist eine Rasterzelle
-- (x,y), kein Himmelskörper selbst - die eigentlichen Objekte (Basen, Außenposten,
-- Asteroidenfelder, Nebel) sitzen auf einer "position" (Slot-Nummer) INNERHALB eines Sektors,
-- analog zur Galaxie:System:Position-Adressierung bekannter Browser-4X-Spiele. 8 Slots/Sektor
-- in Phase 1 (wie viele davon tatsächlich belegt werden, entscheidet seed.php/GameEngine).
CREATE TABLE sectors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    x SMALLINT UNSIGNED NOT NULL,
    y SMALLINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_sectors_coords (x, y)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Spieler-Sternenbasen ("Burgen"). Rohstoff-Bestände direkt auf der Zeile, nicht in einer
-- Extra-Tabelle - es gibt nur 3 Rohstoffe, eine Aufspaltung wäre hier Overengineering.
-- last_tick_at ist die Produktions-Uhr (lazy, wie bei last_collected_at im Vorgängerprojekt).
CREATE TABLE bases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id INT UNSIGNED NOT NULL,
    sector_id INT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    name VARCHAR(40) NOT NULL DEFAULT 'Heimatbasis',
    titan INT UNSIGNED NOT NULL DEFAULT 500,
    deuterium INT UNSIGNED NOT NULL DEFAULT 300,
    credits INT UNSIGNED NOT NULL DEFAULT 200,
    last_tick_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bases_slot (sector_id, position),
    FOREIGN KEY (player_id) REFERENCES players(id),
    FOREIGN KEY (sector_id) REFERENCES sectors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_bases_player ON bases (player_id);

-- Neutrale Plünderungsziele - kein Spieler, aber feste "Beute" und eine Verteidigungsstärke.
CREATE TABLE npc_outposts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sector_id INT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    name VARCHAR(40) NOT NULL,
    defense_strength INT UNSIGNED NOT NULL,
    loot_titan INT UNSIGNED NOT NULL,
    loot_deuterium INT UNSIGNED NOT NULL,
    loot_credits INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_npc_outposts_slot (sector_id, position),
    FOREIGN KEY (sector_id) REFERENCES sectors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ressourcen-Bonus für eine KOLONISIERTE Basis auf demselben Slot (Kolonieschiff-Zielgebiet) -
-- bonus_percent wirkt auf die Produktion der Basis, die hier später gegründet wird.
CREATE TABLE asteroid_fields (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sector_id INT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    bonus_percent TINYINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_asteroid_fields_slot (sector_id, position),
    FOREIGN KEY (sector_id) REFERENCES sectors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reiner Flavor-/taktischer Slot (z.B. Sensor-Störung) - Effekt wird erst in Phase 2 verdrahtet,
-- Spalte liegt aber schon bereit, damit seed.php sie befüllen kann.
CREATE TABLE nebulae (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sector_id INT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    effect VARCHAR(40) NOT NULL,
    UNIQUE KEY uq_nebulae_slot (sector_id, position),
    FOREIGN KEY (sector_id) REFERENCES sectors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- WICHTIG für GameEngine (Phase 2): ein (sector_id, position)-Slot darf nur in GENAU EINER der
-- vier Tabellen (bases, npc_outposts, asteroid_fields, nebulae) belegt sein. Das lässt sich nicht
-- über eine klassische Cross-Table-UNIQUE-Constraint erzwingen - jede Code-Stelle, die einen
-- neuen Slot belegt (Kolonisierung, Seed), MUSS vorher alle vier Tabellen prüfen.

-- --- Ausbaubare Basis-Module: Level + Warteschlange statt Einmalkauf, von Anfang an (dasselbe
-- Muster, das sich im Vorgängerprojekt bewährt hat - siehe Kommentare dort zu virtueller Zeit
-- in der Kettenverarbeitung und "Kosten beim Einreihen abbuchen, nicht beim Start"). ---
CREATE TABLE building_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL,
    description VARCHAR(160) NOT NULL,
    effect ENUM(
        'command', 'titan_production', 'deuterium_production',
        'shipyard', 'shield', 'sensor', 'hangar'
    ) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE building_levels (
    building_type_id INT UNSIGNED NOT NULL,
    level TINYINT UNSIGNED NOT NULL,
    cost_titan INT UNSIGNED NOT NULL,
    cost_deuterium INT UNSIGNED NOT NULL,
    cost_credits INT UNSIGNED NOT NULL,
    build_seconds INT UNSIGNED NOT NULL,
    effect_amount INT UNSIGNED NOT NULL,
    PRIMARY KEY (building_type_id, level),
    FOREIGN KEY (building_type_id) REFERENCES building_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Aktueller Level je Basis+Gebäudetyp. KEINE Zeile = Level 0 (nie gebaut) - analog zum
-- Vorgängerprojekt, kein Platzhalter-Level-0-Insert nötig.
CREATE TABLE base_buildings (
    base_id INT UNSIGNED NOT NULL,
    building_type_id INT UNSIGNED NOT NULL,
    level TINYINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('idle', 'building') NOT NULL DEFAULT 'idle',
    started_at DATETIME NULL,
    finishes_at DATETIME NULL,
    PRIMARY KEY (base_id, building_type_id),
    FOREIGN KEY (base_id) REFERENCES bases(id),
    FOREIGN KEY (building_type_id) REFERENCES building_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Wartende (noch nicht gestartete) Bauaufträge - Rohstoffe werden beim EINREIHEN abgebucht,
-- nicht erst beim Start (sonst ist die Kettenverarbeitung über virtuelle Zeit nicht
-- deterministisch, siehe GameEngine-Kommentar in Phase 2).
CREATE TABLE base_build_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    base_id INT UNSIGNED NOT NULL,
    building_type_id INT UNSIGNED NOT NULL,
    target_level TINYINT UNSIGNED NOT NULL,
    cost_titan_paid INT UNSIGNED NOT NULL,
    cost_deuterium_paid INT UNSIGNED NOT NULL,
    cost_credits_paid INT UNSIGNED NOT NULL,
    queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (base_id) REFERENCES bases(id),
    FOREIGN KEY (building_type_id) REFERENCES building_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Schiffsklassen & Flotten ---
CREATE TABLE ship_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL,
    description VARCHAR(160) NOT NULL,
    attack INT UNSIGNED NOT NULL,
    shield INT UNSIGNED NOT NULL,
    hull INT UNSIGNED NOT NULL,
    speed INT UNSIGNED NOT NULL,
    cargo_capacity INT UNSIGNED NOT NULL,
    cost_titan INT UNSIGNED NOT NULL,
    cost_deuterium INT UNSIGNED NOT NULL,
    cost_credits INT UNSIGNED NOT NULL,
    build_seconds INT UNSIGNED NOT NULL,
    is_flagship TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Im Hangar GEBAUTE, noch nicht losgeschickte Schiffe je Basis - erst beim Abflug entsteht
-- daraus eine fleets-Zeile (siehe unten).
CREATE TABLE base_ships (
    base_id INT UNSIGNED NOT NULL,
    ship_type_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (base_id, ship_type_id),
    FOREIGN KEY (base_id) REFERENCES bases(id),
    FOREIGN KEY (ship_type_id) REFERENCES ship_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Eine Flotte UNTERWEGS oder stationiert an einem Ziel. origin_* bleibt für die Rückkehrreise
-- (Mission 'attack'/'plunder' fliegt automatisch zurück, 'station'/'colonize'/'transport' nicht).
CREATE TABLE fleets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id INT UNSIGNED NOT NULL,
    origin_sector_id INT UNSIGNED NOT NULL,
    origin_position TINYINT UNSIGNED NOT NULL,
    target_sector_id INT UNSIGNED NOT NULL,
    target_position TINYINT UNSIGNED NOT NULL,
    mission ENUM('attack', 'plunder', 'station', 'colonize', 'transport') NOT NULL,
    energy_preset ENUM('shields', 'weapons', 'speed') NOT NULL DEFAULT 'weapons',
    status ENUM('outbound', 'returning', 'arrived') NOT NULL DEFAULT 'outbound',
    cargo_titan INT UNSIGNED NOT NULL DEFAULT 0,
    cargo_deuterium INT UNSIGNED NOT NULL DEFAULT 0,
    cargo_credits INT UNSIGNED NOT NULL DEFAULT 0,
    departed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    arrives_at DATETIME NOT NULL,
    FOREIGN KEY (player_id) REFERENCES players(id),
    FOREIGN KEY (origin_sector_id) REFERENCES sectors(id),
    FOREIGN KEY (target_sector_id) REFERENCES sectors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_fleets_player ON fleets (player_id);
CREATE INDEX idx_fleets_arrival ON fleets (status, arrives_at);

CREATE TABLE fleet_ships (
    fleet_id INT UNSIGNED NOT NULL,
    ship_type_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    PRIMARY KEY (fleet_id, ship_type_id),
    FOREIGN KEY (fleet_id) REFERENCES fleets(id),
    FOREIGN KEY (ship_type_id) REFERENCES ship_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kampfbericht nach einer deterministischen Auflösung bei Ankunft. report_json enthält den
-- Rundenverlauf (Schildabsorption, Verluste je Runde) - Detailtabelle wäre für Phase 1 verfrüht,
-- JSON reicht, bis der genaue Rundenverlauf in Phase 2 feststeht.
CREATE TABLE combat_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attacker_player_id INT UNSIGNED NOT NULL,
    defender_player_id INT UNSIGNED NULL,
    sector_id INT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    outcome ENUM('attacker_win', 'defender_win', 'draw') NOT NULL,
    loot_titan INT UNSIGNED NOT NULL DEFAULT 0,
    loot_deuterium INT UNSIGNED NOT NULL DEFAULT 0,
    loot_credits INT UNSIGNED NOT NULL DEFAULT 0,
    report_json JSON NOT NULL,
    resolved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attacker_player_id) REFERENCES players(id),
    FOREIGN KEY (defender_player_id) REFERENCES players(id),
    FOREIGN KEY (sector_id) REFERENCES sectors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_combat_reports_attacker ON combat_reports (attacker_player_id);
CREATE INDEX idx_combat_reports_defender ON combat_reports (defender_player_id);

-- --- Allianzen & Diplomatie ---
CREATE TABLE alliances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL UNIQUE,
    tag VARCHAR(8) NOT NULL UNIQUE,
    founder_player_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (founder_player_id) REFERENCES players(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE alliance_memberships (
    player_id INT UNSIGNED NOT NULL PRIMARY KEY,
    alliance_id INT UNSIGNED NOT NULL,
    role ENUM('leader', 'member') NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (player_id) REFERENCES players(id),
    FOREIGN KEY (alliance_id) REFERENCES alliances(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Symmetrisch gemeint (A<->B), aber als zwei Zeilen gespeichert (A->B und B->A) statt eine
-- Sortier-Konvention (LEAST/GREATEST) zu erzwingen - einfacher zu lesen und zu aktualisieren,
-- kostet nur doppelt so viele (sehr wenige) Zeilen.
CREATE TABLE diplomacy (
    alliance_id INT UNSIGNED NOT NULL,
    other_alliance_id INT UNSIGNED NOT NULL,
    status ENUM('ally', 'nap', 'war') NOT NULL,
    PRIMARY KEY (alliance_id, other_alliance_id),
    FOREIGN KEY (alliance_id) REFERENCES alliances(id),
    FOREIGN KEY (other_alliance_id) REFERENCES alliances(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sector_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_player_id INT UNSIGNED NOT NULL,
    sector_id INT UNSIGNED NULL,
    alliance_id INT UNSIGNED NULL,
    body VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_player_id) REFERENCES players(id),
    FOREIGN KEY (sector_id) REFERENCES sectors(id),
    FOREIGN KEY (alliance_id) REFERENCES alliances(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- schema_migrations selbst wird von bin/migrate.php vor der ersten Migration angelegt, nicht
-- hier - sonst würde dieses CREATE TABLE auf die bereits von migrate.php erstellte Tabelle
-- treffen und fehlschlagen.
