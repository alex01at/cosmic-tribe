-- Gegen Credits lässt sich eine zweite (dritte, ...) Baustelle/Werft-Bestellung eines ANDEREN
-- Typs sofort parallel starten, statt kostenlos in der Warteschlange zu warten. Die normale,
-- kostenlose Warteschlange (eine Baustelle/ein Werft-Auftrag zur Zeit, FIFO) bleibt unverändert -
-- paid_parallel markiert nur die zusätzlichen, unabhängig laufenden Zeilen, die beim Fertigwerden
-- NIE aus der kostenlosen Warteschlange nachziehen (sonst würde eine einmalige Zahlung eine
-- dauerhafte zweite Lane erkaufen).
ALTER TABLE base_buildings ADD COLUMN paid_parallel TINYINT(1) UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE base_ship_queue ADD COLUMN paid_parallel TINYINT(1) UNSIGNED NOT NULL DEFAULT 0;

-- Preis skaliert mit der (durch game_speed bereits skalierten) Bauzeit, die übersprungen wird -
-- pro Universum vom Betreiber einstellbar (Admin-Panel, wie game_speed).
ALTER TABLE universes ADD COLUMN parallel_build_rate DECIMAL(8,4) UNSIGNED NOT NULL DEFAULT 0.5000;
