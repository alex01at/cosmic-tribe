-- Statische Katalogdaten (Gebäudetypen/-level, Schiffstypen) - im Gegensatz zu seed.php (zufällige
-- Galaxie, NPC-Inhalte, Testspieler) ist das feste Spieldefinition, gehört darum in eine Migration,
-- nicht ins Seed-Skript. Zahlen sind Platzhalter für Phase 1 - echtes Balancing folgt in Phase 2,
-- sobald GameEngine/CombatResolver die tatsächlichen Formeln festlegen.

INSERT INTO building_types (id, name, description, effect) VALUES
    (1, 'Kommandobrücke', 'Das Herz der Basis - ihr Level bestimmt, wie weit alle anderen Module ausgebaut werden können.', 'command'),
    (2, 'Titan-Raffinerie', 'Verarbeitet Rohstoffe zu Titan - Basis-Baumaterial.', 'titan_production'),
    (3, 'Deuterium-Sammler', 'Sammelt Deuterium aus der Umgebung - Treibstoff für Flotten.', 'deuterium_production'),
    (4, 'Werft', 'Baut und rüstet Schiffe aus - höhere Level schalten stärkere Schiffsklassen frei.', 'shipyard'),
    (5, 'Schildgenerator', 'Schützt die Basis selbst vor Angriffen.', 'shield'),
    (6, 'Langstreckensensor', 'Erkennt anfliegende Flotten frühzeitig.', 'sensor'),
    (7, 'Hangar', 'Lagerkapazität für gebaute, noch nicht losgeschickte Schiffe.', 'hangar');

-- Kommandobrücke: effect_amount unbenutzt (0) - nur ihr LEVEL zählt als Deckel für die anderen
-- Module, analog zum Hauptgebäude-Muster aus dem Vorgängerprojekt. NUR die Kommandobrücke selbst
-- hat bei Level 1 cost=0/build_seconds=0 (automatisch vorhanden, siehe seed.php) - alle ANDEREN
-- Gebäudetypen kosten auch auf Level 1 regulär etwas, sie müssen aktiv gebaut werden.
INSERT INTO building_levels (building_type_id, level, cost_titan, cost_deuterium, cost_credits, build_seconds, effect_amount) VALUES
    (1, 1,     0,    0,   0,     0, 0),
    (1, 2,   800,  400, 200,   900, 0),
    (1, 3,  2000, 1000, 500,  1800, 0),
    (1, 4,  4500, 2200, 1200, 3600, 0),
    (1, 5,  9000, 4500, 2500, 7200, 0),

    (2, 1,   400,  200,   0,   600, 20),
    (2, 2,  1000,  500,   0,  1200, 40),
    (2, 3,  2200, 1100,   0,  2400, 75),
    (2, 4,  4500, 2200,   0,  4200, 125),
    (2, 5,  8500, 4200,   0,  7200, 200),

    (3, 1,   300,  300,   0,   600, 15),
    (3, 2,   800,  800,   0,  1200, 30),
    (3, 3,  1800, 1800,   0,  2400, 55),
    (3, 4,  3800, 3800,   0,  4200, 90),
    (3, 5,  7500, 7500,   0,  7200, 140),

    (4, 1,   600,  300,   0,   900, 0),
    (4, 2,  1500,  800,   0,  1800, 10),
    (4, 3,  3200, 1800,   0,  3000, 20),
    (4, 4,  6500, 3800,   0,  5400, 35),
    (4, 5, 12000, 7200,   0,  9000, 50),

    (5, 1,   500,  250, 100,   900, 500),
    (5, 2,  1200,  600, 250,  1800, 1200),
    (5, 3,  2800, 1400, 600,  3000, 2500),
    (5, 4,  5800, 2900, 1200, 5400, 4500),
    (5, 5, 11000, 5500, 2200, 9000, 8000),

    (6, 1,   300,  150,   0,   600, 2),
    (6, 2,   700,  350,   0,  1200, 3),
    (6, 3,  1600,  800,   0,  2400, 4),
    (6, 4,  3400, 1700,   0,  4200, 6),
    (6, 5,  6800, 3400,   0,  7200, 9),

    (7, 1,   400,  200,   0,   600, 20),
    (7, 2,  1000,  500,   0,  1200, 40),
    (7, 3,  2200, 1100,   0,  2400, 70),
    (7, 4,  4500, 2200,   0,  4200, 110),
    (7, 5,  8500, 4200,   0,  7200, 160);

INSERT INTO ship_types (id, name, description, attack, shield, hull, speed, cargo_capacity, cost_titan, cost_deuterium, cost_credits, build_seconds, is_flagship) VALUES
    (1, 'Scout', 'Schnell und unbewaffnet - erkundet Sektoren, kämpft nicht.', 1, 10, 50, 100, 0, 300, 100, 0, 300, 0),
    (2, 'Abfangjäger', 'Schneller Nahkampf-Jäger, schwach gepanzert.', 25, 20, 150, 70, 10, 1200, 600, 0, 900, 0),
    (3, 'Kreuzer', 'Schwer bewaffnet und gepanzert, langsam.', 80, 100, 600, 35, 50, 4500, 2500, 0, 2400, 0),
    (4, 'Transporter', 'Unbewaffnet, riesiger Frachtraum - Handel und Plünderung abtransportieren.', 1, 30, 200, 50, 500, 2000, 800, 0, 1200, 0),
    (5, 'Kolonieschiff', 'Einmalig verbraucht - gründet eine neue Basis auf einem freien Slot.', 1, 50, 300, 30, 0, 8000, 4000, 2000, 3600, 0),
    (6, 'Flaggschiff', 'Individuell konfigurierbar (Basiswerte, Energie-Presets verschieben Angriff/Schild/Tempo).', 150, 200, 1500, 45, 100, 15000, 8000, 5000, 7200, 1);
