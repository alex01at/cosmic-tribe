<?php

declare(strict_types=1);

/**
 * Erzeugt eine neue Galaxie (Sektor-Raster + verstreute NPC-Außenposten/Asteroidenfelder/Nebel)
 * für EIN Universum. Logik aus bin/seed.php extrahiert, damit sowohl die Erstinstallation
 * (Universum 1) als auch das Admin-Panel ("neues Universum anlegen") denselben Code nutzen.
 */
final class GalaxyGenerator
{
    private const POSITIONS_PER_SECTOR = 8;
    private const NPC_OUTPOST_CHANCE = 0.08;
    private const ASTEROID_FIELD_CHANCE = 0.05;
    private const NEBULA_CHANCE = 0.04;

    private const NPC_NAMES = [
        'Verlassene Mine', 'Piratennest', 'Schrottplatz-Wache', 'Handelsposten-Ruine',
        'Alte Relaisstation', 'Grenzwache', 'Schmugglerversteck', 'Forschungsruine',
    ];

    public static function generate(PDO $pdo, int $universeId, int $size): void
    {
        $sectorStmt = $pdo->prepare('INSERT INTO sectors (universe_id, x, y) VALUES (?, ?, ?)');
        $sectorIds = []; // [x][y] => sector_id
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                $sectorStmt->execute([$universeId, $x, $y]);
                $sectorIds[$x][$y] = (int) $pdo->lastInsertId();
            }
        }

        $npcOutpostStmt = $pdo->prepare(
            'INSERT INTO npc_outposts (sector_id, position, name, defense_strength, loot_titan, loot_deuterium, loot_credits)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $asteroidFieldStmt = $pdo->prepare(
            'INSERT INTO asteroid_fields (sector_id, position, bonus_percent) VALUES (?, ?, ?)'
        );
        $nebulaStmt = $pdo->prepare('INSERT INTO nebulae (sector_id, position, effect) VALUES (?, ?, ?)');

        foreach ($sectorIds as $column) {
            foreach ($column as $sectorId) {
                for ($position = 0; $position < self::POSITIONS_PER_SECTOR; $position++) {
                    $roll = mt_rand() / mt_getrandmax();

                    if ($roll < self::NPC_OUTPOST_CHANCE) {
                        $strength = random_int(50, 400);
                        $npcOutpostStmt->execute([
                            $sectorId, $position, self::NPC_NAMES[array_rand(self::NPC_NAMES)], $strength,
                            $strength * random_int(2, 5), $strength * random_int(1, 3), $strength * random_int(1, 2),
                        ]);
                    } elseif ($roll < self::NPC_OUTPOST_CHANCE + self::ASTEROID_FIELD_CHANCE) {
                        $asteroidFieldStmt->execute([$sectorId, $position, random_int(10, 40)]);
                    } elseif ($roll < self::NPC_OUTPOST_CHANCE + self::ASTEROID_FIELD_CHANCE + self::NEBULA_CHANCE) {
                        $nebulaStmt->execute([$sectorId, $position, 'sensor_dampening']);
                    }
                }
            }
        }
    }
}
