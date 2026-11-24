-- Mehrere Universen (Server): jede Welt hat ihre eigene Galaxie, eigene Spielerzahl-Obergrenze
-- und eigene Geschwindigkeit. Scoping-Ansatz bewusst sparsam: universe_id sitzt NUR auf sectors,
-- players und alliances - alles, was per Fremdschlüssel an sector_id/player_id hängt (bases,
-- fleets, combat_reports, base_buildings, ...), ist dadurch automatisch korrekt isoliert, ohne
-- dass jede dieser Tabellen selbst eine universe_id braucht.
CREATE TABLE universes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    max_players INT UNSIGNED NOT NULL DEFAULT 100,
    galaxy_size SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    game_speed DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bestehende Galaxie/Spieler/Allianzen werden "Universum 1" - die bisherige game_speed
-- (game_settings-Override, falls gesetzt, sonst 20 wie im config.php-Default) wandert mit.
INSERT INTO universes (id, name, status, max_players, galaxy_size, game_speed)
SELECT 1, 'Universum 1', 'open', 500, 20,
       COALESCE((SELECT setting_value FROM game_settings WHERE setting_key = 'game_speed'), '20');

-- sectors: Koordinaten sind jetzt nur noch INNERHALB eines Universums eindeutig.
ALTER TABLE sectors ADD COLUMN universe_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id;
UPDATE sectors SET universe_id = 1;
ALTER TABLE sectors DROP INDEX uq_sectors_coords;
ALTER TABLE sectors ADD UNIQUE KEY uq_sectors_universe_coords (universe_id, x, y);
ALTER TABLE sectors ADD CONSTRAINT fk_sectors_universe FOREIGN KEY (universe_id) REFERENCES universes (id);
ALTER TABLE sectors ALTER COLUMN universe_id DROP DEFAULT;

-- players: ein Gerät kann in mehreren Universen je einen eigenen Charakter haben - device_id und
-- username sind daher nur noch pro Universum eindeutig, nicht mehr global.
ALTER TABLE players ADD COLUMN universe_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id;
UPDATE players SET universe_id = 1;
ALTER TABLE players DROP INDEX device_id;
ALTER TABLE players DROP INDEX username;
ALTER TABLE players ADD UNIQUE KEY uq_players_device_universe (device_id, universe_id);
ALTER TABLE players ADD UNIQUE KEY uq_players_universe_username (universe_id, username);
ALTER TABLE players ADD CONSTRAINT fk_players_universe FOREIGN KEY (universe_id) REFERENCES universes (id);
ALTER TABLE players ALTER COLUMN universe_id DROP DEFAULT;

-- alliances: Name/Tag sind nur noch pro Universum eindeutig, damit zwei Welten nicht um dieselben
-- Namen konkurrieren.
ALTER TABLE alliances ADD COLUMN universe_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id;
UPDATE alliances SET universe_id = 1;
ALTER TABLE alliances DROP INDEX name;
ALTER TABLE alliances DROP INDEX tag;
ALTER TABLE alliances ADD UNIQUE KEY uq_alliances_universe_name (universe_id, name);
ALTER TABLE alliances ADD UNIQUE KEY uq_alliances_universe_tag (universe_id, tag);
ALTER TABLE alliances ADD CONSTRAINT fk_alliances_universe FOREIGN KEY (universe_id) REFERENCES universes (id);
ALTER TABLE alliances ALTER COLUMN universe_id DROP DEFAULT;

-- game_settings.game_speed ist damit abgelöst durch universes.game_speed (pro Welt statt global) -
-- der Override-Wert wurde oben bereits nach Universum 1 übernommen.
DELETE FROM game_settings WHERE setting_key = 'game_speed';
