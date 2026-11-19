-- Nachträge aus dem Advisor-Review vor Phase 2 - nie Migration 001/002 nachträglich editieren,
-- auch wenn noch keine echten Spieler betroffen sind (Disziplin von Anfang an).

-- Asteroidenfeld-Bonus: wird beim Kolonisieren auf die neue Basis ÜBERTRAGEN (die
-- asteroid_fields-Zeile wird dabei gelöscht) statt dauerhaft als separater Slot-Eintrag parallel
-- zur Basis zu existieren - löst den Widerspruch zwischen "Slot gehört nur einer Tabelle" und
-- "Bonus für eine kolonisierte Basis auf demselben Slot" auf.
ALTER TABLE bases ADD COLUMN resource_bonus_percent TINYINT UNSIGNED NOT NULL DEFAULT 0;

-- Anfänger-/Plünderungsschutz: bis zu diesem Zeitpunkt kann die Basis nicht angegriffen werden.
-- Endet vorzeitig, sobald der Spieler selbst eine fremde Basis angreift (siehe GameEngine).
ALTER TABLE bases ADD COLUMN protected_until DATETIME NULL;

-- NPC-Außenposten regenerieren ihre Beute lazy über die Zeit statt sie dauerhaft zu verlieren -
-- sonst könnten viele parallele Raubzüge denselben Außenposten beliebig oft leerräumen.
ALTER TABLE npc_outposts ADD COLUMN last_raided_at DATETIME NULL;
