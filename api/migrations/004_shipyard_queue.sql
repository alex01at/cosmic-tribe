-- Werft-Warteschlange (Schiffsbau) - fehlte bewusst in Phase 2 (siehe dortiger Kommentar), aber ohne
-- einen API-Weg zu Schiffen bleibt das ganze Spiel unspielbar (kein Flottenversand, kein Kampf zum
-- Testen moeglich). Gleiches Level+Warteschlange-Muster wie base_build_queue, aber FIFO auf
-- Schiffs-STUECKZAHL statt auf Gebaeude-Level: die erste Zeile (kleinste id) ist der "aktive" Auftrag
-- (started_at/finishes_at gesetzt), nachfolgende Zeilen sind erst vorgemerkt (beide NULL) und werden
-- in VIRTUELLER Zeit verkettet, sobald die vorherige Zeile fertig ist - exakt dieselbe Lektion wie bei
-- base_build_queue in Phase 2.
CREATE TABLE base_ship_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    base_id INT UNSIGNED NOT NULL,
    ship_type_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_cost_titan INT UNSIGNED NOT NULL,
    unit_cost_deuterium INT UNSIGNED NOT NULL,
    unit_cost_credits INT UNSIGNED NOT NULL,
    unit_build_seconds INT UNSIGNED NOT NULL,
    started_at DATETIME NULL,
    finishes_at DATETIME NULL,
    FOREIGN KEY (base_id) REFERENCES bases(id),
    FOREIGN KEY (ship_type_id) REFERENCES ship_types(id)
);

CREATE INDEX idx_base_ship_queue_base ON base_ship_queue(base_id, id);
