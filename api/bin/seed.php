<?php

declare(strict_types=1);

/**
 * Generiert Universum 1 (20x20-Start-Galaxie, 400 Sektoren) mit verstreuten NPC-Außenposten,
 * Asteroidenfeldern und Nebeln, sowie 2 Testspielern mit je einer Heimatbasis
 * (Kommandobrücke automatisch Level 1, wie ein Rathaus - siehe Migration 002).
 *
 * Nicht idempotent mit sich selbst wiederholenden IDs - bei erneutem Aufruf gegen eine schon
 * befüllte DB zuerst bin/reset.php (folgt in Phase 2) oder die Tabellen manuell leeren.
 *
 * Weitere Universen legt der Betreiber über das Admin-Panel an (AdminEngine::createUniverse(),
 * nutzt dieselbe GalaxyGenerator-Logik).
 *
 * Aufruf: php bin/seed.php
 */

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/GalaxyGenerator.php';

const UNIVERSE_ID = 1;
const GALAXY_SIZE = 20; // 20x20 Sektoren
const POSITIONS_PER_SECTOR = 8;

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    echo 'Lege Universum 1: ' . GALAXY_SIZE . 'x' . GALAXY_SIZE . " Sektoren an...\n";
    GalaxyGenerator::generate($pdo, UNIVERSE_ID, GALAXY_SIZE);
    echo '  ' . (GALAXY_SIZE * GALAXY_SIZE) . " Sektoren angelegt.\n";

    // Belegte Slots werden NICHT lokal mitgezaehlt (die Wuerfel-Ergebnisse aus GalaxyGenerator
    // sind von hier aus nicht sichtbar) - stattdessen wie in Auth::findFreeSlot() live gegen alle
    // vier Belegungs-Tabellen geprueft; $claimedByUs verhindert nur, dass die zwei Testspieler
    // selbst denselben Slot ziehen, bevor ihre eigene Basis-Zeile existiert.
    $claimedByUs = [];
    $occupiedCheckStmt = $pdo->prepare(
        'SELECT 1 FROM bases WHERE sector_id = ? AND position = ?
         UNION SELECT 1 FROM npc_outposts WHERE sector_id = ? AND position = ?
         UNION SELECT 1 FROM asteroid_fields WHERE sector_id = ? AND position = ?
         UNION SELECT 1 FROM nebulae WHERE sector_id = ? AND position = ?'
    );
    $sectorStmt = $pdo->prepare('SELECT id, x, y FROM sectors WHERE universe_id = ?');
    $sectorStmt->execute([UNIVERSE_ID]);
    $sectorIds = []; // [x][y] => sector_id
    foreach ($sectorStmt->fetchAll() as $row) {
        $sectorIds[(int) $row['x']][(int) $row['y']] = (int) $row['id'];
    }

    echo "Lege 2 Testspieler mit Heimatbasis an...\n";
    $playerStmt = $pdo->prepare('INSERT INTO players (universe_id, device_id, username) VALUES (?, ?, ?)');
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
        $playerStmt->execute([UNIVERSE_ID, $tp['device'], $tp['username']]);
        $playerId = (int) $pdo->lastInsertId();

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $tokenStmt->execute([$playerId, $tokenHash, 'seed']);

        // Freien Slot suchen (fernab vom Rand, damit immer Nachbar-Sektoren für Bewegung da
        // sind) - einfaches Zufalls-Retry statt komplexer Platzierungslogik, reicht für 2
        // Testspieler in einer 400-Sektoren-Galaxie locker.
        while (true) {
            $x = random_int(2, GALAXY_SIZE - 3);
            $y = random_int(2, GALAXY_SIZE - 3);
            $sectorId = $sectorIds[$x][$y];
            $position = random_int(0, POSITIONS_PER_SECTOR - 1);
            if (isset($claimedByUs[$sectorId][$position])) {
                continue;
            }
            $occupiedCheckStmt->execute([$sectorId, $position, $sectorId, $position, $sectorId, $position, $sectorId, $position]);
            if ($occupiedCheckStmt->fetch() === false) {
                break;
            }
        }
        $claimedByUs[$sectorId][$position] = true;

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
