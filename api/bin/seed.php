<?php

declare(strict_types=1);

/**
 * Generiert eine 20x20-Start-Galaxie (400 Sektoren) mit verstreuten NPC-Außenposten,
 * Asteroidenfeldern und Nebeln, sowie 2 Testspieler mit je einer Heimatbasis
 * (Kommandobrücke automatisch Level 1, wie ein Rathaus - siehe Migration 002).
 *
 * Nicht idempotent mit sich selbst wiederholenden IDs - bei erneutem Aufruf gegen eine schon
 * befüllte DB zuerst bin/reset.php (folgt in Phase 2) oder die Tabellen manuell leeren.
 *
 * Aufruf: php bin/seed.php
 */

require __DIR__ . '/../src/Database.php';

const GALAXY_SIZE = 20; // 20x20 Sektoren
const POSITIONS_PER_SECTOR = 8; // Slots je Sektor (Basen/Außenposten/Asteroidenfelder/Nebel teilen sich diese)
const NPC_OUTPOST_CHANCE = 0.08; // je Slot
const ASTEROID_FIELD_CHANCE = 0.05;
const NEBULA_CHANCE = 0.04;

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    echo 'Lege ' . GALAXY_SIZE . 'x' . GALAXY_SIZE . " Sektoren an...\n";
    $sectorStmt = $pdo->prepare('INSERT INTO sectors (x, y) VALUES (?, ?)');
    $sectorIds = []; // [x][y] => sector_id
    for ($x = 0; $x < GALAXY_SIZE; $x++) {
        for ($y = 0; $y < GALAXY_SIZE; $y++) {
            $sectorStmt->execute([$x, $y]);
            $sectorIds[$x][$y] = (int) $pdo->lastInsertId();
        }
    }
    echo '  ' . (GALAXY_SIZE * GALAXY_SIZE) . " Sektoren angelegt.\n";

    // Belegte Slots je Sektor merken (sector_id => [position => true]), damit Spieler-Basen
    // später nicht mit NPC-Inhalten kollidieren (siehe "WICHTIG für GameEngine"-Kommentar in
    // Migration 001 - ein Slot darf nur in EINER der vier Tabellen auftauchen).
    $occupiedSlots = [];

    $npcNames = [
        'Verlassene Mine', 'Piratennest', 'Schrottplatz-Wache', 'Handelsposten-Ruine',
        'Alte Relaisstation', 'Grenzwache', 'Schmugglerversteck', 'Forschungsruine',
    ];

    echo "Verstreue NPC-Außenposten, Asteroidenfelder, Nebel...\n";
    $npcOutpostStmt = $pdo->prepare(
        'INSERT INTO npc_outposts (sector_id, position, name, defense_strength, loot_titan, loot_deuterium, loot_credits)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $asteroidFieldStmt = $pdo->prepare(
        'INSERT INTO asteroid_fields (sector_id, position, bonus_percent) VALUES (?, ?, ?)'
    );
    $nebulaStmt = $pdo->prepare('INSERT INTO nebulae (sector_id, position, effect) VALUES (?, ?, ?)');

    $npcCount = 0;
    $asteroidCount = 0;
    $nebulaCount = 0;

    foreach ($sectorIds as $x => $column) {
        foreach ($column as $y => $sectorId) {
            for ($position = 0; $position < POSITIONS_PER_SECTOR; $position++) {
                $roll = mt_rand() / mt_getrandmax();

                if ($roll < NPC_OUTPOST_CHANCE) {
                    $strength = random_int(50, 400);
                    $npcOutpostStmt->execute([
                        $sectorId, $position, $npcNames[array_rand($npcNames)], $strength,
                        $strength * random_int(2, 5), $strength * random_int(1, 3), $strength * random_int(1, 2),
                    ]);
                    $occupiedSlots[$sectorId][$position] = true;
                    $npcCount++;
                } elseif ($roll < NPC_OUTPOST_CHANCE + ASTEROID_FIELD_CHANCE) {
                    $asteroidFieldStmt->execute([$sectorId, $position, random_int(10, 40)]);
                    $occupiedSlots[$sectorId][$position] = true;
                    $asteroidCount++;
                } elseif ($roll < NPC_OUTPOST_CHANCE + ASTEROID_FIELD_CHANCE + NEBULA_CHANCE) {
                    $nebulaStmt->execute([$sectorId, $position, 'sensor_dampening']);
                    $occupiedSlots[$sectorId][$position] = true;
                    $nebulaCount++;
                }
            }
        }
    }
    echo "  {$npcCount} NPC-Außenposten, {$asteroidCount} Asteroidenfelder, {$nebulaCount} Nebel.\n";

    echo "Lege 2 Testspieler mit Heimatbasis an...\n";
    $playerStmt = $pdo->prepare('INSERT INTO players (device_id, username) VALUES (?, ?)');
    $tokenStmt = $pdo->prepare('INSERT INTO user_tokens (player_id, token_hash, device_label) VALUES (?, ?, ?)');
    $baseStmt = $pdo->prepare(
        'INSERT INTO bases (player_id, sector_id, position, name) VALUES (?, ?, ?, ?)'
    );
    $commandBridgeStmt = $pdo->prepare(
        "INSERT INTO base_buildings (base_id, building_type_id, level, status) VALUES (?, 1, 1, 'idle')"
    );

    $testPlayers = [
        ['device' => 'seed-test-player-1', 'username' => 'Kommandant Vega'],
        ['device' => 'seed-test-player-2', 'username' => 'Kommandantin Lyra'],
    ];

    foreach ($testPlayers as $tp) {
        $playerStmt->execute([$tp['device'], $tp['username']]);
        $playerId = (int) $pdo->lastInsertId();

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $tokenStmt->execute([$playerId, $tokenHash, 'seed']);

        // Freien Slot suchen (fernab vom Rand, damit immer Nachbar-Sektoren für Bewegung da
        // sind) - einfaches Zufalls-Retry statt komplexer Platzierungslogik, reicht für 2
        // Testspieler in einer 400-Sektoren-Galaxie locker.
        do {
            $x = random_int(2, GALAXY_SIZE - 3);
            $y = random_int(2, GALAXY_SIZE - 3);
            $sectorId = $sectorIds[$x][$y];
            $position = random_int(0, POSITIONS_PER_SECTOR - 1);
        } while (isset($occupiedSlots[$sectorId][$position]));
        $occupiedSlots[$sectorId][$position] = true;

        $baseStmt->execute([$playerId, $sectorId, $position, $tp['username'] . 's Basis']);
        $baseId = (int) $pdo->lastInsertId();
        $commandBridgeStmt->execute([$baseId]);

        echo "  {$tp['username']} (Gerät {$tp['device']}) @ Sektor ({$x},{$y}) Slot {$position}, Token: {$token}\n";
    }

    $pdo->commit();
    echo "Fertig.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
