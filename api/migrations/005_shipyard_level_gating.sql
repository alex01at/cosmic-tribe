ALTER TABLE ship_types ADD COLUMN min_shipyard_level SMALLINT UNSIGNED NOT NULL DEFAULT 1;

UPDATE ship_types SET min_shipyard_level = 1 WHERE id IN (1, 2, 4);
UPDATE ship_types SET min_shipyard_level = 2 WHERE id = 5;
UPDATE ship_types SET min_shipyard_level = 3 WHERE id = 3;
UPDATE ship_types SET min_shipyard_level = 5 WHERE id = 6;
